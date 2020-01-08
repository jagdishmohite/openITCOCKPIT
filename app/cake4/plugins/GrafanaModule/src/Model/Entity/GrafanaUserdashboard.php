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

declare(strict_types=1);

namespace GrafanaModule\Model\Entity;

use Cake\ORM\Entity;

/**
 * GrafanaUserdashboard Entity
 *
 * @property int $id
 * @property int $container_id
 * @property int $configuration_id
 * @property string $name
 * @property string $grafana_uid
 * @property string $grafana_url
 *
 * @property Container $container
 * @property Configuration $configuration
 */
class GrafanaUserdashboard extends Entity {
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * Note that when '*' is set to true, this allows all unspecified fields to
     * be mass assigned. For security purposes, it is advised to set '*' to false
     * (or remove it), and explicitly make individual fields accessible as needed.
     *
     * @var array
     */
    protected $_accessible = [
        'container_id'     => true,
        'configuration_id' => true,
        'name'             => true,
        'grafana_uid'      => true,
        'grafana_url'      => true,
        'container'        => true,
        'configuration'    => true,
    ];
}
