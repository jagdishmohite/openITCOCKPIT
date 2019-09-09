<?php
// Copyright (C) <2015>  <it-novum GmbH>
//
// This file is dual licensed
//
// 1.
//	This program is free software: you can redistribute it and/or modify
//	it under the terms of the GNU General Public License as published by
//	the Free Software Foundation, version 3 of the License.
//
//	This program is distributed in the hope that it will be useful,
//	but WITHOUT ANY WARRANTY; without even the implied warranty of
//	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//	GNU General Public License for more details.
//
//	You should have received a copy of the GNU General Public License
//	along with this program.  If not, see <http://www.gnu.org/licenses/>.
//

// 2.
//	If you purchased an openITCOCKPIT Enterprise Edition you can use this file
//	under the terms of the openITCOCKPIT Enterprise Edition license agreement.
//	License agreement and license key will be shipped with the order
//	confirmation.
use App\Form\InstantreportForm;
use App\Model\Table\ContainersTable;
use App\Model\Table\InstantreportsTable;
use App\Model\Table\SystemfailuresTable;
use Cake\ORM\TableRegistry;
use itnovum\openITCOCKPIT\Core\AngularJS\Api;
use itnovum\openITCOCKPIT\Core\DowntimeHostConditions;
use itnovum\openITCOCKPIT\Core\DowntimeServiceConditions;
use itnovum\openITCOCKPIT\Core\FileDebugger;
use itnovum\openITCOCKPIT\Core\StatehistoryHostConditions;
use itnovum\openITCOCKPIT\Core\StatehistoryServiceConditions;
use itnovum\openITCOCKPIT\Core\ValueObjects\StateTypes;
use itnovum\openITCOCKPIT\Database\PaginateOMat;
use itnovum\openITCOCKPIT\Filter\InstantreportFilter;


/**
 * @property Instantreport $Instantreport
 * @property Host $Host
 * @property Service $Service
 * @property Timeperiod $Timeperiod
 * @property StatehistoryHost $StatehistoryHost
 * @property DowntimeHost $DowntimeHost
 * @property StatehistoryService $StatehistoryService
 * @property AppPaginatorComponent $Paginator
 */
class InstantreportsController extends AppController {
    public $layout = 'blank';


    public function index() {
        if (!$this->isApiRequest()) {
            //Only ship template for AngularJs
            return;
        }
        $InstantreportFilter = new InstantreportFilter($this->request);
        /** @var $InstantreportsTable InstantreportsTable */
        $InstantreportsTable = TableRegistry::getTableLocator()->get('Instantreports');

        $PaginateOMat = new PaginateOMat($this->Paginator, $this, $this->isScrollRequest(), $InstantreportFilter->getPage());
        $MY_RIGHTS = [];
        if ($this->hasRootPrivileges === false) {
            /** @var $ContainersTable ContainersTable */
            $ContainersTable = TableRegistry::getTableLocator()->get('Containers');
            $MY_RIGHTS = $ContainersTable->resolveChildrenOfContainerIds($this->MY_RIGHTS);
        }
        $instantreports = $InstantreportsTable->getInstantreportsIndex($InstantreportFilter, $PaginateOMat, $MY_RIGHTS);
        foreach ($instantreports as $index => $instantreport) {
            $instantreports[$index]['allowEdit'] = $this->isWritableContainer($instantreport['Instantreport']['container_id']);
        }
        $this->set('instantreports', $instantreports);
        $toJson = ['instantreports', 'paging'];
        if ($this->isScrollRequest()) {
            $toJson = ['instantreports', 'scroll'];
        }
        $this->set('_serialize', $toJson);
    }

    public function add() {
        if (!$this->isApiRequest()) {
            //Only ship template for AngularJs
            return;
        }
        /** @var $InstantreportsTable InstantreportsTable */
        $InstantreportsTable = TableRegistry::getTableLocator()->get('Instantreports');
        if ($this->request->is('post') && $this->isAngularJsRequest()) {
            $instantreport = $InstantreportsTable->newEmptyEntity();
            $instantreport = $InstantreportsTable->patchEntity($instantreport, $this->request->data('Instantreport'));
            $InstantreportsTable->save($instantreport);
            if ($instantreport->hasErrors()) {
                $this->response->statusCode(400);
                $this->serializeCake4ErrorMessage($instantreport);
                return;
            } else {
                //No errors
                $this->serializeCake4Id($instantreport);
            }
            $this->set('instantreport', $instantreport);
            $this->set('_serialize', ['instantreport']);
        }
    }

    public function edit($id = null) {
        if (!$this->isApiRequest()) {
            //Only ship HTML template for angular
            return;
        }

        /** @var $InstantreportsTable InstantreportsTable */
        $InstantreportsTable = TableRegistry::getTableLocator()->get('Instantreports');

        if (!$InstantreportsTable->existsById($id)) {
            throw new NotFoundException(__('Instant report not found'));
        }
        $instantreport = $InstantreportsTable->getInstantreportForEdit($id);
        if (!$this->allowedByContainerId($instantreport['Instantreport']['container_id'])) {
            $this->render403();
            return;
        }
        if ($this->request->is('get') && $this->isAngularJsRequest()) {
            //Return instant report information
            $this->set('instantreport', $instantreport);
            $this->set('_serialize', ['instantreport']);
            return;
        }

        if ($this->request->is('post') || $this->request->is('put')) {
            $data = $this->request->data('Instantreport');
            $instantreport = $InstantreportsTable->get($id);
            $instantreport = $InstantreportsTable->patchEntity($instantreport, $data);
            $InstantreportsTable->save($instantreport);
            if ($instantreport->hasErrors()) {
                $this->response->statusCode(400);
                $this->set('error', $instantreport->getErrors());
                $this->set('_serialize', ['error']);
                return;
            }

            $this->set('instantreport', $instantreport);
            $this->set('_serialize', ['instantreport']);
        }
    }

    public function generate($id = null) {
        if (!$this->isApiRequest()) {
            //Only ship HTML template
            return;
        }

        $instantreportForm = new InstantreportForm();
        $instantreportForm->execute($this->request->data);

        if(!empty($instantreportForm->getErrors())){
            $this->response->statusCode(400);
            $this->set('error', $instantreportForm->getErrors());
            $this->set('_serialize', ['error']);
            return;
        }

        $instantreportId = $this->request->data('instantreport_id');
        $fromDate = strtotime($this->request->data('from_date') . ' 00:00:00');
        $toDate = strtotime($this->request->data('to_date') . ' 23:59:59');
        $instantReportData = $this->generateReport(
            $instantreportId,
            $fromDate,
            $toDate
        );

        return;
        $downtimeReportForm = new DowntimereportForm();
        $downtimeReportForm->execute($this->request->data);

        $User = new \itnovum\openITCOCKPIT\Core\ValueObjects\User($this->Auth);
        $UserTime = UserTime::fromUser($User);

        if (!empty($downtimeReportForm->getErrors())) {
            $this->response->statusCode(400);
            $this->set('error', $downtimeReportForm->getErrors());
            $this->set('_serialize', ['error']);
            return;
        }

        /** @var $TimeperiodsTable TimeperiodsTable */
        $TimeperiodsTable = TableRegistry::getTableLocator()->get('Timeperiods');
        $timeperiod = $TimeperiodsTable->getTimeperiodWithTimerangesById($this->request->data('timeperiod_id'));
        if (empty($timeperiod['Timeperiod']['timeperiod_timeranges'])) {
            $this->response->statusCode(400);
            $this->set('error', [
                'timeperiod_id' => [
                    'empty' => 'There are no time frames defined. Time evaluation report data is not available for the selected period.'
                ]
            ]);
            $this->set('_serialize', ['error']);
            return;
        }
        /** @var HostsTable $HostsTable */
        $HostsTable = TableRegistry::getTableLocator()->get('Hosts');
        $fromDate = strtotime($this->request->data('from_date') . ' 00:00:00');
        $toDate = strtotime($this->request->data('to_date') . ' 23:59:59');
        $evaluationType = $this->request->data('evaluation_type');
        $reflectionState = $this->request->data('reflection_state');

        $hostsUuids = $HostsTable->getHostsByContainerId($this->MY_RIGHTS, 'list', 'uuid');
        if (empty($hostsUuids)) {
            $this->response->statusCode(400);
            $this->set('error', [
                'hosts' => [
                    'empty' => 'There are no hosts for downtime report available.'
                ]
            ]);
            $this->set('_serialize', ['error']);
            return;
        }
        $downtimeReport = $this->createReport(
            $fromDate,
            $toDate,
            $evaluationType,
            $reflectionState,
            $timeperiod['Timeperiod']['timeperiod_timeranges'],
            $hostsUuids,
            $UserTime
        );

        if ($downtimeReport === null) {
            $this->response->statusCode(400);
            $this->set('error', [
                'no_downtimes' => [
                    'empty' => __('No downtimes within specified time found (%s - %s) !',
                        date('d.m.Y', $fromDate),
                        date('d.m.Y', $toDate)
                    )
                ]
            ]);
            $this->set('_serialize', ['error']);
            return;
        }

        $this->set('downtimeReport', $downtimeReport);
        $this->set('_serialize', ['downtimeReport']);
    }

    /**
     * @param $instantReportId
     * @param $fromDate
     * @param $toDate
     */
    private function generateReport($instantReportId, $fromDate, $toDate) {
        FileDebugger::dump('test ***************'.$instantReportId);
        /** @var $InstantreportsTable InstantreportsTable */
        $InstantreportsTable = TableRegistry::getTableLocator()->get('Instantreports');

        if (!$InstantreportsTable->existsById($instantReportId)) {
            throw new NotFoundException(__('Instant report not found'));
        }
        $instantreport = $InstantreportsTable->getInstantreportByIdCake4($instantReportId);
        FileDebugger::dump($instantreport);

        return;
        $instantReportDetails['onlyHosts'] = ($instantReport['Instantreport']['evaluation'] == 1);
        $instantReportDetails['onlyServices'] = ($instantReport['Instantreport']['evaluation'] == 3);
        $instantReportDetails['summary'] = $instantReport['Instantreport']['summary'];
        $instantReportDetails['name'] = $instantReport['Instantreport']['name'];
        $instantReportData = [];
        $allHostsServices = $this->getAllHostsServices($instantReport);
        if (!empty($allHostsServices['Hosts']) || !empty($allHostsServices['Services'])) {
            $TimeperiodsTable = TableRegistry::getTableLocator()->get('Timeperiods');
            $timeperiod = $TimeperiodsTable->find()
                ->where(['id' => $instantReport['Instantreport']['timeperiod_id']])
                ->contain('TimeperiodTimeranges')
                ->first()
                ->toArray();
            $timeSlicesGlobal = Hash::insert(
                $this->Instantreport->createDateRanges(
                    $baseStartDate,
                    $baseEndDate,
                    $timeperiod['timeperiod_timeranges']),
                '{n}.is_downtime', false
            );

            //Default time slices (no downtimes in report)
            if ($instantReport['Instantreport']['downtimes'] !== '1') {
                $timeSlices = $timeSlicesGlobal;
            }


            $startDateSqlFormat = date('Y-m-d H:i:s', strtotime($startDate));
            $endDateSqlFormat = date('Y-m-d H:i:s', strtotime($endDate));

            $globalDowntimes = [];

            if ($instantReport['Instantreport']['downtimes'] === '1') {
                /** @var $SystemfailuresTable SystemfailuresTable */
                $SystemfailuresTable = TableRegistry::getTableLocator()->get('Systemfailures');

                $globalDowntimes = $SystemfailuresTable->getSystemfailuresForReporting(
                    strtotime($startDate),
                    strtotime($endDate)
                );

                $globalDowntimes = ['Systemfailure' => Hash::extract($globalDowntimes, '{n}.Systemfailure')];
            }

            $totalTime = Hash::apply(Hash::map($timeSlicesGlobal, '{n}', ['Instantreport', 'calculateTotalTime']), '{n}', 'array_sum');
            $instantReportDetails['totalTime'] = $totalTime;

            foreach ($allHostsServices['Hosts'] as $hostUuid => $name) {
                //Process conditions
                $Conditions = new StatehistoryHostConditions();
                $Conditions->setOrder(['StatehistoryHost.state_time' => 'asc']);

                if ($instantReport['Instantreport']['reflection'] == Instantreport::STATE_HARD_ONLY) {
                    $StateTypes = new StateTypes();
                    $StateTypes->setStateType(1, true);
                    $Conditions->setStateTypes($StateTypes);
                }
                $Conditions->setFrom(strtotime($startDate));
                $Conditions->setTo(strtotime($endDate));
                $Conditions->setHostUuid($hostUuid);
                $Conditions->setUseLimit(false);

                //Query state history records for hosts
                $query = $this->StatehistoryHost->getQuery($Conditions);
                $statehistories = $this->StatehistoryHost->find('all', $query);
                $all_statehistories[$hostUuid] = [];
                foreach ($statehistories as $statehistory) {
                    $StatehistoryHost = new \itnovum\openITCOCKPIT\Core\Views\StatehistoryHost($statehistory['StatehistoryHost']);
                    $all_statehistories[$hostUuid]['Statehistory'][] = $StatehistoryHost->toArray();
                }

                if (empty($all_statehistories[$hostUuid]['Statehistory'])) {
                    //Host has no state history record for selected time range
                    //Get last available state history record for this host
                    $query = $this->StatehistoryHost->getLastRecord($Conditions);
                    $record = $this->StatehistoryHost->find('first', $query);
                    if (!empty($record)) {
                        $record['StatehistoryHost']['state_time'] = $startDateSqlFormat;
                        $StatehistoryHost = new \itnovum\openITCOCKPIT\Core\Views\StatehistoryHost($record['StatehistoryHost']);
                        $all_statehistories[$hostUuid]['Statehistory'][] = $StatehistoryHost->toArray();
                    }
                }


                if ($instantReport['Instantreport']['downtimes'] == '1') {
                    //Query downtime records for hosts
                    $DowntimeHostConditions = new DowntimeHostConditions();
                    $DowntimeHostConditions->setOrder(['DowntimeHost.scheduled_start_time' => 'asc']);
                    $DowntimeHostConditions->setFrom(strtotime($startDate));
                    $DowntimeHostConditions->setTo(strtotime($endDate));
                    $DowntimeHostConditions->setHostUuid($hostUuid);


                    $query = $this->DowntimeHost->getQueryForReporting($DowntimeHostConditions);
                    $downtimes = $this->DowntimeHost->find('all', $query);

                    //Merge monitoring downtimes with openITCOCKPIT system failures
                    $downtimes = $this->Instantreport->mergeDowntimesWithSystemfailures(
                        'DowntimeHost',
                        $downtimes,
                        $globalDowntimes['Systemfailure']
                    );


                    $downtimesAndSystemfailures = [];
                    foreach ($downtimes as $downtime) {
                        $DowntimeHost = new \itnovum\openITCOCKPIT\Core\Views\Downtime($downtime['DowntimeHost']);
                        $downtimesAndSystemfailures[] = [
                            'DowntimeHost' => $DowntimeHost->toArray()
                        ];
                    }

                    $timeSlices = $timeSlicesGlobal; //Default time slice if no downtime will be found
                    if (!empty($downtimesAndSystemfailures)) {
                        $downtimesFiltered = $this->Instantreport->mergeTimeOverlapping(
                            array_map(
                                function ($downtime) {
                                    return [
                                        'start_time' => $downtime['DowntimeHost']['scheduledStartTime'],
                                        'end_time'   => $downtime['DowntimeHost']['scheduledEndTime'],
                                    ];
                                },
                                $downtimesAndSystemfailures
                            )
                        );
                        $timeSlices = $this->Instantreport->setDowntimesInTimeslices(
                            $timeSlicesGlobal,
                            $downtimesFiltered
                        );
                        unset($downtimesFiltered);
                    }
                }
                $stateHistoryWithObject[$hostUuid] = $all_statehistories[$hostUuid];

                if (!empty($stateHistoryWithObject)) {
                    $instantReportData['Hosts'][$hostUuid] = $this->Instantreport->generateInstantreportData(
                        $timeSlices,
                        $stateHistoryWithObject,
                        $instantReport['Instantreport']['reflection'] == Instantreport::STATE_HARD_ONLY,
                        true
                    );
                    $instantReportData['Hosts'][$hostUuid]['Host']['name'] = $name;

                    unset($stateHistoryWithObject);
                } else {
                    $instantReportData['Hosts'][$hostUuid]['HostsNotMonitored'] = $this->Host->find('list', [
                        'conditions' => [
                            'Host.uuid' => $hostUuid,
                        ],
                    ]);
                }
            }
            foreach ($allHostsServices['Services'] as $hostUuid => $services) {
                foreach ($services as $serviceUuid => $name) {
                    //Process conditions
                    $Conditions = new StatehistoryServiceConditions();
                    $Conditions->setOrder(['StatehistoryService.state_time' => 'asc']);

                    if ($instantReport['Instantreport']['reflection'] == Instantreport::STATE_HARD_ONLY) {
                        $StateTypes = new StateTypes();
                        $StateTypes->setStateType(1, true);
                        $Conditions->setStateTypes($StateTypes);
                    }
                    $Conditions->setFrom(strtotime($startDate));
                    $Conditions->setTo(strtotime($endDate));
                    $Conditions->setServiceUuid($serviceUuid);
                    $Conditions->setUseLimit(false);

                    //Query state history records for services
                    $query = $this->StatehistoryService->getQuery($Conditions);
                    $statehistories = $this->StatehistoryService->find('all', $query);

                    $all_statehistories[$serviceUuid] = [];
                    foreach ($statehistories as $statehistory) {
                        $StatehistoryService = new \itnovum\openITCOCKPIT\Core\Views\StatehistoryService($statehistory['StatehistoryService']);
                        $all_statehistories[$serviceUuid]['Statehistory'][] = $StatehistoryService->toArray();
                    }

                    if (empty($all_statehistories[$serviceUuid]['Statehistory'])) {
                        //Service has no state history record for selected time range
                        //Get last available state history record for this service
                        $query = $this->StatehistoryService->getLastRecord($Conditions);
                        $record = $this->StatehistoryService->find('first', $query);
                        if (!empty($record)) {
                            $record['StatehistoryService']['state_time'] = $startDateSqlFormat;
                            $StatehistoryService = new \itnovum\openITCOCKPIT\Core\Views\StatehistoryService($record['StatehistoryService']);
                            $all_statehistories[$serviceUuid]['Statehistory'][] = $StatehistoryService->toArray();
                        }
                    }


                    if ($instantReport['Instantreport']['downtimes'] == '1') {
                        //Query downtime records for hosts
                        $DowntimeServiceConditions = new DowntimeServiceConditions();
                        $DowntimeServiceConditions->setOrder(['DowntimeService.scheduled_start_time' => 'asc']);
                        $DowntimeServiceConditions->setFrom(strtotime($startDate));
                        $DowntimeServiceConditions->setTo(strtotime($endDate));
                        $DowntimeServiceConditions->setServiceUuid($serviceUuid);


                        $query = $this->DowntimeService->getQueryForReporting($DowntimeServiceConditions);
                        $downtimes = $this->DowntimeService->find('all', $query);

                        //Merge monitoring downtimes with openITCOCKPIT system failures
                        $downtimes = $this->Instantreport->mergeDowntimesWithSystemfailures(
                            'DowntimeService',
                            $downtimes,
                            $globalDowntimes['Systemfailure']
                        );


                        $downtimesAndSystemfailures = [];
                        foreach ($downtimes as $downtime) {
                            $DowntimeService = new \itnovum\openITCOCKPIT\Core\Views\Downtime($downtime['DowntimeService']);
                            $downtimesAndSystemfailures[] = [
                                'DowntimeService' => $DowntimeService->toArray()
                            ];
                        }

                        $timeSlices = $timeSlicesGlobal;
                        if (!empty($downtimesAndSystemfailures)) {
                            $downtimesFiltered = $this->Instantreport->mergeTimeOverlapping(
                                array_map(
                                    function ($downtime) {
                                        return [
                                            'start_time' => $downtime['DowntimeService']['scheduledStartTime'],
                                            'end_time'   => $downtime['DowntimeService']['scheduledEndTime'],
                                        ];
                                    },
                                    $downtimesAndSystemfailures
                                )
                            );
                            $timeSlices = $this->Instantreport->setDowntimesInTimeslices(
                                $timeSlicesGlobal,
                                $downtimesFiltered
                            );
                            unset($downtimesFiltered);
                        }
                    }
                    $stateHistoryWithObject[$serviceUuid] = $all_statehistories[$serviceUuid];

                    if (!empty($stateHistoryWithObject)) {
                        $instantReportData['Hosts'][$hostUuid]['Services'][$serviceUuid] = $this->Instantreport->generateInstantreportData(
                            $timeSlices,
                            $stateHistoryWithObject,
                            $instantReport['Instantreport']['reflection'] == Instantreport::STATE_HARD_ONLY,
                            false
                        );
                        $instantReportData['Hosts'][$hostUuid]['Services'][$serviceUuid]['Service']['name'] = $name;

                        unset($stateHistoryWithObject);
                    } else {
                        $instantReportService = $this->Service->find('first', [
                            'recursive'  => -1,
                            'contain'    => [
                                'Host'            => [
                                    'fields' => [
                                        'Host.uuid',
                                        'Host.name'
                                    ]
                                ],
                                'Servicetemplate' => [
                                    'fields' => 'Servicetemplate.name',
                                ],
                            ],
                            'conditions' => [
                                'Service.uuid' => $serviceUuid,
                            ],
                            'fields'     => [
                                'Service.name',
                            ],
                        ]);
                        $instantReportData['Hosts'][$instantReportService['Host']['uuid']]['Services']['ServicesNotMonitored'][$serviceUuid] = $instantReportService;
                    }
                }
            }
        }

        if ($reportFormat == Instantreport::FORMAT_PDF) {
            if (empty($this->cronFromDate)) {
                $this->Session->write('instantReportData', $instantReportData);
                $this->Session->write('instantReportDetails', $instantReportDetails);
                $this->redirect([
                    'action' => 'createPdfReport',
                    'ext'    => 'pdf',
                ]);
            } else {
                $binary_path = '/usr/bin/wkhtmltopdf';
                if (file_exists('/usr/local/bin/wkhtmltopdf')) {
                    $binary_path = '/usr/local/bin/wkhtmltopdf';
                }
                $CakePdf = new CakePdf([
                    'engine'             => 'CakePdf.WkHtmlToPdf',
                    'margin'             => [
                        'bottom' => 15,
                        'left'   => 0,
                        'right'  => 0,
                        'top'    => 15,
                    ],
                    'encoding'           => 'UTF-8',
                    'download'           => false,
                    'binary'             => $binary_path,
                    'orientation'        => 'portrait',
                    'filename'           => sprintf('InstantReport_%s.pdf', $instantReport['Instantreport']['name']),
                    'no-pdf-compression' => '*',
                    'image-dpi'          => '900',
                    'background'         => true,
                    'no-background'      => false,
                ]);

                $CakePdf->_engineClass->binary = $binary_path;
                $CakePdf->viewVars(['instantReportData' => $instantReportData, 'instantReportDetails' => $instantReportDetails]);
                $CakePdf->template('create_pdf_report');
                $pdf = $CakePdf->write($this->cronPdfName);

            }
        } else {
            $this->set(compact(['instantReportData', 'instantReportDetails']));
            $this->render('/Elements/load_instant_report_data');
        }
    }

    private function getAllHostsServices($instantReport) {
        /**
         *  $containArray = [
         *      1 => [], // Type Only Hosts
         *      2 => [], // Type Hosts and Services
         *      3 => []  // Type Only Services
         *  ];
         */

        $objectsForInstantReport = [
            'Hosts'    => [],
            'Services' => []
        ];
        switch ($instantReport['Instantreport']['type']) {
            case Instantreport::TYPE_HOSTGROUPS:      //-> 1
                $containArray = [
                    Instantreport::EVALUATION_HOSTS          => [
                        'Host' => [
                            'fields'     => [
                                'Host.uuid',
                                'Host.name'
                            ],
                            'conditions' => [
                                'Host.disabled' => 0
                            ]
                        ]
                    ],
                    Instantreport::EVALUATION_HOSTS_SERVICES => [
                        'Host' => [
                            'fields'     => [
                                'Host.uuid',
                                'Host.name'
                            ],
                            'Service'    => [
                                'fields'          => [
                                    'Service.uuid',
                                    'Service.name'
                                ],
                                'Servicetemplate' => [
                                    'fields' => [
                                        'Servicetemplate.name'
                                    ]
                                ],
                                'conditions'      => [
                                    'Service.disabled' => 0
                                ]
                            ],
                            'conditions' => [
                                'Host.disabled' => 0
                            ]
                        ]
                    ],
                    Instantreport::EVALUATION_SERVICES       => [
                        'Host' => [
                            'fields'     => [
                                'Host.uuid',
                                'Host.name'
                            ],
                            'conditions' => [
                                'Host.disabled' => 0
                            ],
                            'Service'    => [
                                'fields'          => [
                                    'Service.uuid',
                                    'Service.name'
                                ],
                                'conditions'      => [
                                    'Service.disabled' => 0
                                ],
                                'Servicetemplate' => [
                                    'fields' => [
                                        'Servicetemplate.name'
                                    ]
                                ]
                            ]
                        ]
                    ],
                ];
                $instantReportHostgroups = $this->Instantreport->find('first', [
                    'recursive'  => -1,
                    'contain'    => [
                        'Hostgroup' =>
                            $containArray[$instantReport['Instantreport']['evaluation']]
                    ],
                    'conditions' => [
                        'Instantreport.id' => $instantReport['Instantreport']['id']
                    ]
                ]);
                if ($instantReport['Instantreport']['evaluation'] == Instantreport::EVALUATION_HOSTS) {
                    $objectsForInstantReport['Hosts'] = array_unique(
                        Hash::combine($instantReportHostgroups['Hostgroup'], '{n}.Host.{n}.uuid', '{n}.Host.{n}.name')
                    );
                }
                if ($instantReport['Instantreport']['evaluation'] == Instantreport::EVALUATION_HOSTS_SERVICES ||
                    $instantReport['Instantreport']['evaluation'] == Instantreport::EVALUATION_SERVICES) {
                    foreach ($instantReportHostgroups['Hostgroup'] as $hostgroup) {
                        foreach ($hostgroup['Host'] as $host) {
                            $objectsForInstantReport['Hosts'][$host['uuid']] = $host['name'];
                            foreach ($host['Service'] as $service) {
                                $serviceName = $service['name'];
                                if ($serviceName === null || $serviceName === '') {
                                    $serviceName = $service['Servicetemplate']['name'];
                                }
                                $objectsForInstantReport['Services'][$host['uuid']][$service['uuid']] = $serviceName;
                            }
                        }
                    }
                }
                return $objectsForInstantReport;
            case Instantreport::TYPE_HOSTS:           //-> 2
                $containArray = [
                    Instantreport::EVALUATION_HOSTS          => [
                        'Host' => [
                            'fields'     => [
                                'Host.name',
                                'Host.uuid'
                            ],
                            'conditions' => [
                                'Host.disabled' => 0
                            ]
                        ]
                    ],
                    Instantreport::EVALUATION_HOSTS_SERVICES => [
                        'Host' => [
                            'fields'     => [
                                'Host.name',
                                'Host.uuid'
                            ],
                            'conditions' => [
                                'Host.disabled' => 0
                            ],
                            'Service'    => [
                                'fields'          => [
                                    'Service.uuid',
                                    'Service.name'
                                ],
                                'Servicetemplate' => [
                                    'fields' => [
                                        'Servicetemplate.name'
                                    ]
                                ],
                                'conditions'      => [
                                    'Service.disabled' => 0
                                ]
                            ]
                        ]
                    ]
                ];
                $containArray[Instantreport::EVALUATION_SERVICES] = $containArray[Instantreport::EVALUATION_HOSTS_SERVICES];
                $instantReportHosts = $this->Instantreport->find('first', [
                    'recursive'  => -1,
                    'contain'    => $containArray[$instantReport['Instantreport']['evaluation']],
                    'conditions' => [
                        'Instantreport.id' => $instantReport['Instantreport']['id']
                    ]
                ]);

                if ($instantReport['Instantreport']['evaluation'] == Instantreport::EVALUATION_HOSTS) {
                    $objectsForInstantReport['Hosts'] = array_unique(
                        Hash::combine($instantReportHosts['Host'], '{n}.uuid', '{n}.name')
                    );
                }
                if ($instantReport['Instantreport']['evaluation'] == Instantreport::EVALUATION_HOSTS_SERVICES ||
                    $instantReport['Instantreport']['evaluation'] == Instantreport::EVALUATION_SERVICES) {

                    foreach ($instantReportHosts['Host'] as $host) {
                        $objectsForInstantReport['Hosts'][$host['uuid']] = $host['name'];
                        foreach ($host['Service'] as $service) {
                            $serviceName = $service['name'];
                            if ($serviceName === null || $serviceName === '') {
                                $serviceName = $service['Servicetemplate']['name'];
                            }
                            $objectsForInstantReport['Services'][$host['uuid']][$service['uuid']] = $serviceName;
                        }
                    }
                }
                return $objectsForInstantReport;
            case Instantreport::TYPE_SERVICEGROUPS:   //-> 3

                $instantReportServicegroups = $this->Instantreport->find('first', [
                    'recursive'  => -1,
                    'contain'    => [
                        'Servicegroup' => [
                            'Service' => [
                                'fields'          => [
                                    'Service.uuid',
                                    'Service.name'
                                ],
                                'conditions'      => [
                                    'Service.disabled' => 0
                                ],
                                'Servicetemplate' => [
                                    'fields' => [
                                        'Servicetemplate.name'
                                    ]
                                ],
                                'Host'            => [
                                    'fields'     => [
                                        'Host.name',
                                        'Host.uuid'
                                    ],
                                    'conditions' => [
                                        'Host.disabled' => 0
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'conditions' => [
                        'Instantreport.id' => $instantReport['Instantreport']['id']
                    ]
                ]);
                if ($instantReport['Instantreport']['evaluation'] == Instantreport::EVALUATION_HOSTS) {
                    $objectsForInstantReport['Hosts'] = array_unique(
                        Hash::combine($instantReportServicegroups['Servicegroup'], '{n}.Service.{n}.Host.uuid', '{n}.Service.{n}.Host.name')
                    );
                }

                if ($instantReport['Instantreport']['evaluation'] == Instantreport::EVALUATION_HOSTS_SERVICES ||
                    $instantReport['Instantreport']['evaluation'] == Instantreport::EVALUATION_SERVICES) {
                    foreach ($instantReportServicegroups['Servicegroup'] as $servicegroup) {
                        foreach ($servicegroup['Service'] as $service) {
                            $serviceName = $service['name'];
                            if ($serviceName === null || $serviceName === '') {
                                $serviceName = $service['Servicetemplate']['name'];
                            }
                            $objectsForInstantReport['Hosts'][$service['Host']['uuid']] = $service['Host']['name'];
                            $objectsForInstantReport['Services'][$service['Host']['uuid']][$service['uuid']] = $serviceName;
                        }
                    }
                }
                return $objectsForInstantReport;
            case Instantreport::TYPE_SERVICES:        //-> 4
                $instantReportServices = $this->Instantreport->find('first', [
                    'recursive'  => -1,
                    'contain'    => [
                        'Service' => [
                            'Servicetemplate' => [
                                'fields' => [
                                    'Servicetemplate.name'
                                ]
                            ],
                            'Host'            => [
                                'fields'     => [
                                    'Host.uuid',
                                    'Host.name'
                                ],
                                'conditions' => [
                                    'Host.disabled' => 0
                                ]
                            ],
                            'fields'          => [
                                'Service.name',
                                'Service.uuid'
                            ],
                            'conditions'      => [
                                'Service.disabled' => 0
                            ]
                        ]
                    ],
                    'conditions' => [
                        'Instantreport.id' => $instantReport['Instantreport']['id']
                    ]
                ]);


                if ($instantReport['Instantreport']['evaluation'] == Instantreport::EVALUATION_HOSTS) {
                    $objectsForInstantReport['Hosts'] = array_unique(
                        Hash::combine($instantReportServices['Service'], '{n}.Host.uuid', '{n}.Host.name')
                    );
                }

                if ($instantReport['Instantreport']['evaluation'] == Instantreport::EVALUATION_HOSTS_SERVICES ||
                    $instantReport['Instantreport']['evaluation'] == Instantreport::EVALUATION_SERVICES) {

                    foreach ($instantReportServices['Service'] as $service) {
                        $serviceName = $service['name'];
                        if ($serviceName === null || $serviceName === '') {
                            $serviceName = $service['Servicetemplate']['name'];
                        }
                        $objectsForInstantReport['Hosts'][$service['Host']['uuid']] = $service['Host']['name'];
                        $objectsForInstantReport['Services'][$service['Host']['uuid']][$service['uuid']] = $serviceName;
                    }
                }
                return $objectsForInstantReport;
        }
    }

    public function delete($id = null) {
        if (!$this->request->is('post')) {
            throw new MethodNotAllowedException();
        }

        /** @var $InstantreportsTable InstantreportsTable */
        $InstantreportsTable = TableRegistry::getTableLocator()->get('Instantreports');

        if (!$InstantreportsTable->exists($id)) {
            throw new NotFoundException(__('Instant report not found'));
        }

        $instantreport = $InstantreportsTable->getInstantreportById($id);
        if (!$this->allowedByContainerId(Hash::extract($instantreport, 'Instantreport.container_id'))) {
            $this->render403();
            return;
        }
        $instantreportEntity = $InstantreportsTable->get($id);
        if ($InstantreportsTable->delete($instantreportEntity)) {
            $this->set('success', true);
            $this->set('_serialize', ['success']);
            return;
        }

        $this->response->statusCode(500);
        $this->set('success', false);
        $this->set('_serialize', ['success']);
        return;
    }

    public function createPdfReport() {
        $instantReportDetails = $this->Session->read('instantReportDetails');
        $reportName = '';
        if (isset($instantReportDetails['name'])) {
            $reportName = $instantReportDetails['name'];
        }
        $this->set('instantReportData', $this->Session->read('instantReportData'));
        $this->set('instantReportDetails', $instantReportDetails);
        if ($this->Session->check('instantReportData')) {
            $this->Session->delete('instantReportData');
        }
        if ($this->Session->check('instantReportDetails')) {
            $this->Session->delete('instantReportDetails');
        }

        $binary_path = '/usr/bin/wkhtmltopdf';
        if (file_exists('/usr/local/bin/wkhtmltopdf')) {
            $binary_path = '/usr/local/bin/wkhtmltopdf';
        }
        $this->pdfConfig = [
            'engine'             => 'CakePdf.WkHtmlToPdf',
            'margin'             => [
                'bottom' => 5,
                'left'   => 0,
                'right'  => 0,
                'top'    => 5,
            ],
            'encoding'           => 'UTF-8',
            'download'           => true,
            'binary'             => $binary_path,
            'orientation'        => 'portrait',
            'filename'           => sprintf('Instantreport_%s.pdf', $reportName),
            'no-pdf-compression' => '*',
            'image-dpi'          => '900',
            'background'         => true,
            'no-background'      => false,
        ];
    }

    public function loadContainers() {
        if (!$this->isAngularJsRequest()) {
            throw new MethodNotAllowedException();
        }
        /** @var $ContainersTable ContainersTable */
        $ContainersTable = TableRegistry::getTableLocator()->get('Containers');

        if ($this->hasRootPrivileges === true) {
            $containers = $ContainersTable->easyPath($this->MY_RIGHTS, OBJECT_HOSTGROUP, [], $this->hasRootPrivileges);
        } else {
            $containers = $ContainersTable->easyPath($this->getWriteContainers(), OBJECT_HOSTGROUP, [], $this->hasRootPrivileges);
        }
        $containers = Api::makeItJavaScriptAble($containers);

        $this->set('containers', $containers);
        $this->set('_serialize', ['containers']);
    }

}
