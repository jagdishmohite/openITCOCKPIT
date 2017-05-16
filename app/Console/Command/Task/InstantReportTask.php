<?php
// Copyright (C) <2015>  <it-novum GmbH>
//
// This file is dual licensed
//
// 1.
//  This program is free software: you can redistribute it and/or modify
//  it under the terms of the GNU General Public License as published by
//  the Free Software Foundation, version 3 of the License.
//
//  This program is distributed in the hope that it will be useful,
//  but WITHOUT ANY WARRANTY; without even the implied warranty of
//  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//  GNU General Public License for more details.
//
//  You should have received a copy of the GNU General Public License
//  along with this program.  If not, see <http://www.gnu.org/licenses/>.
//
// 2.
//  If you purchased an openITCOCKPIT Enterprise Edition you can use this file
//  under the terms of the openITCOCKPIT Enterprise Edition license agreement.
//  License agreement and license key will be shipped with the order
//  confirmation.

use \itnovum\openITCOCKPIT\Core\Interfaces\CronjobInterface;

class InstantReportTask extends AppShell implements CronjobInterface
{
    public $uses = ['Instantreport', 'Systemsetting'];
    public $_systemsettings;

    public function execute($quiet = false){
        $this->_systemsettings = $this->Systemsetting->findAsArraySection('MONITORING');
        $this->params['quiet'] = $quiet;
        $this->stdout->styles('green', ['text' => 'green']);
        $this->stdout->styles('red', ['text' => 'red']);
        $this->out('Sending Instant Reports...');
        $allInstantReports = $this->Instantreport->find('all', [
            'recursive' => -1,
            'conditions' => [
                'Instantreport.send_email' => '1',
            ],
            'contain'    => [
                'User.email'
            ],
        ]);
        $toSend = false;
        foreach($allInstantReports as $mInstantReport){
            $fromDate = $this->Instantreport->getFromDate($mInstantReport['Instantreport']['last_send_date'], $mInstantReport['Instantreport']['send_interval']);
            if($fromDate === false) continue;
            if(empty($mInstantReport['User'])) continue;
            $emailsToSend = [];
            foreach ($mInstantReport['User'] as $userToSend){
                $emailsToSend[] = $userToSend['email'];
            }
            if(empty($emailsToSend)) continue;
            App::uses('CakePdf', 'CakePdf.Pdf');
            App::import('Controller', 'Instantreports');
            $InstantreportsController = new InstantreportsController();
            $InstantreportsController->cronFromDate = $fromDate;
            $InstantreportsController->cronPdfName = APP.'tmp/InstantReport_'.$mInstantReport['Instantreport']['id'].'.pdf';
            $InstantreportsController->generate($mInstantReport['Instantreport']['id']);
            $attachmentArray['InstantReport.pdf'] = [
                'file'     => $InstantreportsController->cronPdfName,
                'mimetype' => 'application/pdf',
            ];
            $sendIntervals = $this->Instantreport->getSendIntervals();
            $subject = $sendIntervals[$mInstantReport['Instantreport']['send_interval']].' Instant Report [ID=#'.$mInstantReport['Instantreport']['id'].']';
            $Email = new CakeEmail();
            $Email->config('default');
            $Email->from([$this->_systemsettings['MONITORING']['MONITORING.FROM_ADDRESS'] => $this->_systemsettings['MONITORING']['MONITORING.FROM_NAME']]);
            $Email->to($emailsToSend);
            $Email->subject($subject);
            $Email->attachments($attachmentArray);
            $toSend = true;
            if ($Email->send('Attached you find the automatically generated Instant Report!')) {
                $this->out('Report "'.$mInstantReport['Instantreport']['id'].'" sent to mail address "'.implode(', ', $emailsToSend).'"', false);
                $this->Instantreport->id = $mInstantReport['Instantreport']['id'];
                $this->Instantreport->saveField('last_send_date', date('Y-m-d H:i:s'));
                $this->out('<green>   Ok</green>');
            } else {
                $this->out('ERROR sending report  "'.$mInstantReport['Instantreport']['id'].'" to mail address "'.implode(', ', $emailsToSend).'" !', false);
                $this->out('<red>   Error</red>');
            }
        }
        if(!$toSend){
            $this->out('<green>No emails to send</green>');
        }
        $this->hr();
    }

    /**
     * @return string all unseen messages from inbox
     */
    private function ackHostsAndServices(){
        $this->_systemsettings = $this->Systemsetting->findAsArraySection('MONITORING');
        if(empty($this->_systemsettings['MONITORING']['MONITORING.ACK_RECEIVER_SERVER']) ||
            empty($this->_systemsettings['MONITORING']['MONITORING.ACK_RECEIVER_ADDRESS']) ||
            empty($this->_systemsettings['MONITORING']['MONITORING.ACK_RECEIVER_PASSWORD'])){
            return ['success' => '<red>Error</red>', 'messages' => ['Some of ACK_ values were not provided in system settings']];
        }
        $serverParts = explode('/', $this->_systemsettings['MONITORING']['MONITORING.ACK_RECEIVER_SERVER']);
        if(count($serverParts) < 2){
            return ['success' => '<red>Error</red>', 'messages' => ['ACK_RECEIVER_SERVER has wrong format']];
        }
        $serverAndPort = explode(':', $serverParts[0]);
        if(count($serverAndPort) < 2){
            return ['success' => '<red>Error</red>', 'messages' => ['ACK_RECEIVER_SERVER has wrong format. Either connection URL is wrong or port was not provided.']];
        }

        $serverURL = trim($serverAndPort[0]);
        $serverPort = trim($serverAndPort[1]);
        $serverProtocol =trim($serverParts[1]);
        $serverSSL = (isset($serverParts[2]) && trim($serverParts[2]) === 'ssl');

        if($serverProtocol != 'imap'){
            return ['success' => '<red>Error</red>', 'messages' => ['Only IMAP protocol is supported.']];
        }

        $mailbox = new \JJG\Imap($serverURL, $this->_systemsettings['MONITORING']['MONITORING.ACK_RECEIVER_ADDRESS'], $this->_systemsettings['MONITORING']['MONITORING.ACK_RECEIVER_PASSWORD'], $serverPort, $serverProtocol, $serverSSL, 'INBOX');
        if(!empty($mailbox->error)){
            return ['success' => '<red>Error</red>', 'messages' => [$mailbox->error]];
        }
        $myEmails = $mailbox->searchForEmails();
        $acks = [];
        $success = '<green>Ok</green>';
        $acknowledged = 0;
        if($myEmails !== false) {
            $this->out('Received ' . (count($myEmails)) . ' email(s)...   ');
            foreach ($myEmails as $myEmailId) {
                $messArr = $mailbox->getMessage($myEmailId);
                $this->out('Parsing email from '.((isset($messArr['sender']) && !empty($messArr['sender']))?$messArr['sender']:$messArr['from']));
                $parsedValues = $this->parseAckInformation($messArr['body']);
                $mailbox->deleteMessage($myEmailId);
                if (empty($parsedValues)) continue;
                $acknowledged++;
                if (empty($parsedValues['ACK_SERVICEUUID']) && !empty($parsedValues['ACK_HOSTUUID'])) {
                    $this->Externalcommand->setHostAck([
                        'hostUuid' => $parsedValues['ACK_HOSTUUID'],
                        'author' => $messArr['sender'],
                        'comment' => __('Acknowledged per mail'),
                        'sticky' => 1,
                        'type' => 'hostOnly'
                    ]);
                    $this->out('Host ' . $parsedValues['ACK_HOSTUUID'] . ' <green>acknowledged</green>');
                } elseif (!empty($parsedValues['ACK_SERVICEUUID']) && !empty($parsedValues['ACK_HOSTUUID'])) {
                    $this->Externalcommand->setServiceAck([
                        'hostUuid' => $parsedValues['ACK_HOSTUUID'],
                        'serviceUuid' => $parsedValues['ACK_SERVICEUUID'],
                        'author' => $messArr['sender'],
                        'comment' => __('Acknowledged per mail'),
                        'sticky' => 1
                    ]);
                    $this->out('Service ' . $parsedValues['ACK_SERVICEUUID'] . ' <green>acknowledged</green>');
                }
            }
        }

        if($acknowledged == 0){
            $acks = ['No hosts and services were acknowledged'];
        }
        $mailbox->disconnect();
        return ['success' => $success, 'messages' => $acks];
    }

}