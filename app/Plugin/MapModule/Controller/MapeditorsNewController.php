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
use itnovum\openITCOCKPIT\Core\System\FileUploadSize;
use itnovum\openITCOCKPIT\Core\Views\UserTime;
use Symfony\Component\Finder\Finder;


/**
 * Class MapeditorsNewController
 * @property Map $Map
 * @property MapNew $MapNew
 * @property Host $Host
 * @property Service $Service
 * @property Hoststatus $Hoststatus
 * @property Servicestatus $Servicestatus
 *
 */
class MapeditorsNewController extends MapModuleAppController {
    public $layout = 'blank';

    public $uses = [
        'MapModule.Map',
        'MapModule.MapNew',
        'Host',
        'Service',
        'Hostgroup',
        MONITORING_HOSTSTATUS,
        MONITORING_SERVICESTATUS
    ];


    /**
     * @param int $id
     */
    ///map_module/mapeditors_new/view/20
    public function view($id) {
        if (!$this->isApiRequest()) {
            $this->layout = 'angularjs';
            $isFullscreen = false;
            if ($this->request->query('fullscreen') === 'true') {
                $this->layout = 'angularjs_fullscreen';
                $isFullscreen = true;
            }
            $this->set('isFullscreen', $isFullscreen);
            //Only ship template
            return;
        }

        $id = (int)$id;
        if (!$this->Map->exists($id)) {
            throw new NotFoundException();
        }
        $map = $this->Map->find('first', [
            'recursive'  => -1,
            'contain'    => [
                'Container',
                'Mapitem',
                'Mapline',
                'Mapgadget',
                'Mapicon',
                'Maptext',
            ],
            'conditions' => [
                'Map.id' => $id
            ]
        ]);


        $containerIdsToCheck = Hash::extract($map, 'Container.{n}.MapsToContainer.container_id');
        if (!$this->allowedByContainerId($containerIdsToCheck, false)) {
            $this->render403();
            return;
        }

        $acl = [
            'hosts'         => [
                'browser' => isset($this->PERMISSIONS['hosts']['browser'])
            ],
            'services'      => [
                'browser' => isset($this->PERMISSIONS['services']['browser'])
            ],
            'hostgroups'    => [
                'extended' => isset($this->PERMISSIONS['hostgroups']['extended'])
            ],
            'servicegroups' => [
                'extended' => isset($this->PERMISSIONS['servicegroups']['extended'])
            ]
        ];

        $this->set('map', $map);
        $this->set('ACL', $acl);

        $this->set('_serialize', ['map', 'ACL']);

    }

    public function mapitem() {
        if (!$this->isApiRequest()) {
            return;
        }

        $objectId = (int)$this->request->query('objectId');
        if ($objectId <= 0) {
            throw new RuntimeException('Invalid object id');
        }


        switch ($this->request->query('type')) {
            case 'host':
                $host = $this->Host->find('first', [
                    'recursive'  => -1,
                    'contain'    => [
                        'Container'
                    ],
                    'fields'     => [
                        'Host.id',
                        'Host.uuid',
                        'Host.name'
                    ],
                    'conditions' => [
                        'Host.id'       => $objectId,
                        'Host.disabled' => 0
                    ]
                ]);
                if (!empty($host)) {
                    if ($this->hasRootPrivileges === false) {
                        if (!$this->allowedByContainerId(Hash::extract($host, 'Container.{n}.HostsToContainer.container_id'))) {
                            $allowView = false;
                            break;
                        }
                    }
                    $allowView = true;
                    $properties = $this->MapNew->getHostInformation(
                        $this->Service,
                        $this->Hoststatus,
                        $this->Servicestatus,
                        $host
                    );
                    break;
                }
                $allowView = false;
                break;

            case 'service':
                $service = $this->Service->find('first', [
                    'recursive'  => -1,
                    'fields'     => [
                        'Service.id',
                        'Service.name',
                        'Service.uuid'
                    ],
                    'contain'    => [
                        'Host'            => [
                            'fields' => [
                                'Host.id',
                                'Host.uuid',
                                'Host.name'
                            ],
                            'Container',
                        ],
                        'Servicetemplate' => [
                            'fields' => [
                                'Servicetemplate.name'
                            ]
                        ]
                    ],
                    'conditions' => [
                        'Service.id'       => $objectId,
                        'Service.disabled' => 0
                    ],
                ]);

                if (!empty($service)) {
                    if ($this->hasRootPrivileges === false) {
                        if (!$this->allowedByContainerId(Hash::extract($service, 'Host.Container.{n}.HostsToContainer.container_id'))) {
                            $allowView = false;
                            break;
                        }
                    }
                    $allowView = true;
                    $properties = $this->MapNew->getServiceInformation($this->Servicestatus, $service);
                    break;
                }
                $allowView = false;
                break;

            case 'hostgroup':
                $hostgroup = $this->Hostgroup->find('first', [
                    'recursive'  => -1,
                    'contain'    => [
                        'Container' => [
                            'fields' => [
                                'Container.name'
                            ]
                        ],
                        'Host'      => [
                            'Container',
                            'fields'     => [
                                'Host.id',
                                'Host.uuid'
                            ],
                            'conditions' => [
                                'Host.disabled' => 0
                            ]
                        ]
                    ],
                    'fields'     => [
                        'Hostgroup.id',
                        'Hostgroup.description'
                    ],
                    'conditions' => [
                        'Hostgroup.id' => $objectId
                    ]
                ]);
                if (!empty($hostgroup)) {
                    if ($this->hasRootPrivileges === false) {
                        if (!$this->allowedByContainerId(Hash::extract($hostgroup, 'Host.{n}.Container.{n}.HostsToContainer.container_id'))) {
                            $allowView = false;
                            break;
                        }
                    }
                    $allowView = true;
                    $properties = $this->MapNew->getHostgroupInformation(
                        $this->Service,
                        $this->Hoststatus,
                        $this->Servicestatus,
                        $hostgroup
                    );
                    break;
                }
                $allowView = false;
                break;

            case 'servicegroup':
                $allowView = true;
                $properties = [
                    'icon'       => 'error.png',
                    'color'      => 'text-primary',
                    'background' => 'bg-color-blueLight',
                    'data'       => []
                ];
                break;
            case 'map':
                $allowView = true;
                $properties = [
                    'icon'       => 'error.png',
                    'color'      => 'text-primary',
                    'background' => 'bg-color-blueLight',
                    'data'       => []
                ];
                break;
            default:
                throw new RuntimeException('Unknown map item type');
                break;
        }

        $this->set('type', $this->request->query('type'));
        $this->set('data', $properties);
        $this->set('allowView', $allowView);
        $this->set('_serialize', ['type', 'allowView', 'data']);
    }

    public function mapline() {
        //Only ship template
        return;
    }

    public function mapicon() {
        //Only ship template
        return;
    }

    public function maptext() {
        //Only ship template
        return;
    }

    public function perfdatatext() {
        //Only ship template
        return;
    }

    public function graph() {
        if (!$this->isApiRequest()) {
            return;
        }

        $serviceId = (int)$this->request->query('serviceId');
        $service = $this->Service->find('first', [
            'recursive'  => -1,
            'fields'     => [
                'Service.id',
                'Service.uuid'
            ],
            'contain'    => [
                'Host'            => [
                    'fields' => [
                        'Host.id',
                        'Host.uuid',
                        'Host.name'
                    ],
                    'Container',
                ],
                'Servicetemplate' => [
                    'fields' => [
                        'Servicetemplate.name'
                    ]
                ]
            ],
            'conditions' => [
                'Service.id'       => $serviceId,
                'Service.disabled' => 0
            ],
        ]);
        if (empty($service)) {
            throw new NotFoundException('Service not found!');
        }

        if ($this->hasRootPrivileges === false) {
            if (!$this->allowedByContainerId(Hash::extract($service, 'Host.Container.{n}.HostsToContainer.container_id'))) {
                $this->set('allowView', false);
                $this->set('_serialize', ['allowView']);
            }
        }

        $Service = new \itnovum\openITCOCKPIT\Core\Views\Service($service);
        $Host = new \itnovum\openITCOCKPIT\Core\Views\Host($service);
        $this->set('host', $Host->toArray());
        $this->set('service', $Service->toArray());
        $this->set('allowView', true);
        $this->set('_serialize', ['allowView', 'host', 'service']);
    }

    public function tacho() {
        //Only ship template
        return;
    }

    public function cylinder() {
        //Only ship template
        return;
    }

    public function trafficlight() {
        //Only ship template
        return;
    }


    public function mapsummary() {
        if (!$this->isApiRequest()) {
            return;
        }

        $objectId = (int)$this->request->query('objectId');
        if ($objectId <= 0) {
            throw new RuntimeException('Invalid object id');
        }

        $UserTime = new UserTime($this->Auth->user('timezone'), $this->Auth->user('dateformat'));

        switch ($this->request->query('type')) {
            case 'host':
                $host = $this->Host->find('first', [
                    'recursive'  => -1,
                    'contain'    => [
                        'Container'
                    ],
                    'fields'     => [
                        'Host.id',
                        'Host.uuid',
                        'Host.name',
                        'Host.description'
                    ],
                    'conditions' => [
                        'Host.id'       => $objectId,
                        'Host.disabled' => 0
                    ]
                ]);
                if (!empty($host)) {
                    if ($this->hasRootPrivileges === false) {
                        if (!$this->allowedByContainerId(Hash::extract($host, 'Container.{n}.HostsToContainer.container_id'))) {
                            $this->render403();
                            return;
                        }
                    }
                    $summary = $this->MapNew->getHostSummary(
                        $this->Service,
                        $this->Hoststatus,
                        $this->Servicestatus,
                        $host,
                        $UserTime
                    );
                    $this->set('type', 'host');
                    $this->set('summary', $summary);
                    $this->set('_serialize', ['host', 'summary']);
                    return;
                }

                throw new NotFoundException('Host not found!');
                return;

            case 'service':
                $service = $this->Service->find('first', [
                    'recursive'  => -1,
                    'fields'     => [
                        'Service.uuid',
                        'Service.description'
                    ],
                    'contain'    => [
                        'Host'            => [
                            'fields' => [
                                'Host.id',
                                'Host.uuid',
                                'Host.name'
                            ],
                            'Container',
                        ],
                        'Servicetemplate' => [
                            'fields' => [
                                'Servicetemplate.name'
                            ]
                        ]
                    ],
                    'conditions' => [
                        'Service.id'       => $objectId,
                        'Service.disabled' => 0
                    ],
                ]);

                if (!empty($service)) {
                    if ($this->hasRootPrivileges === false) {
                        if (!$this->allowedByContainerId(Hash::extract($service, 'Host.Container.{n}.HostsToContainer.container_id'))) {
                            $this->render403();
                            return;
                        }
                    }
                    $summary = $this->MapNew->getServiceSummary(
                        $this->Service,
                        $this->Hoststatus,
                        $this->Servicestatus,
                        $service,
                        $UserTime
                    );
                    $this->set('type', 'service');
                    $this->set('summary', $summary);
                    $this->set('_serialize', ['service', 'summary']);
                    return;

                }
                throw new NotFoundException('Service not found!');
                return;

                break;
            case 'hostgroup':
                $query = [
                    'recursive'  => -1,
                    'contain'    => [
                        'Container' => [
                            'fields' => [
                                'Container.name'
                            ]
                        ],
                        'Host'      => [
                            'Container',
                            'fields'     => [
                                'Host.id',
                                'Host.uuid',
                                'Host.name',
                                'Host.description'
                            ],
                            'conditions' => [
                                'Host.disabled' => 0
                            ]
                        ]
                    ],
                    'fields'     => [
                        'Hostgroup.description'
                    ],
                    'conditions' => [
                        'Hostgroup.id' => $objectId
                    ]
                ];
                if (!$this->hasRootPrivileges) {
                    $query['conditions']['Container.parent_id'] = $this->MY_RIGHTS;
                }
                $hostgroup = $this->Hostgroup->find('first', $query);

                if (!empty($hostgroup)) {
                    if ($this->hasRootPrivileges === false) {
                        if (!$this->allowedByContainerId(Hash::extract($hostgroup, 'Container.{n}.HostsToContainer.container_id'))) {
                            $this->render403();
                            return;
                        }
                    }

                    $summary = $this->MapNew->getHostgroupSummary(
                        $this->Host,
                        $this->Service,
                        $this->Hoststatus,
                        $this->Servicestatus,
                        $hostgroup
                    );
                    $this->set('type', 'hostgroup');
                    $this->set('summary', $summary);
                    $this->set('_serialize', ['hostgroup', 'summary']);
                    return;
                }

                throw new NotFoundException('Host group not found!');
                return;
                break;
            case 'servicegroup':
                break;
            case 'map':
                break;
            default:
                throw new RuntimeException('Unknown map item type');
                break;
        }

    }

    public function edit($id) {
        if (!$this->isApiRequest()) {
            $this->layout = 'angularjs';
            //Only ship template
            return;
        }

        $FileUploadSize = new FileUploadSize();


        $id = (int)$id;
        if (!$this->Map->exists($id)) {
            throw new NotFoundException();
        }
        $map = $this->Map->find('first', [
            'recursive'  => -1,
            'contain'    => [
                'Container',
                'Mapitem',
                'Mapline',
                'Mapgadget',
                'Mapicon',
                'Maptext',
            ],
            'conditions' => [
                'Map.id' => $id
            ]
        ]);

        $this->set('map', $map);
        $this->set('maxUploadLimit', $FileUploadSize->toArray());
        $this->set('_serialize', ['map', 'maxUploadLimit']);
    }

    public function backgroundImages() {
        if (!$this->isApiRequest()) {
            throw new MethodNotAllowedException();
        }


        $finder = new Finder();
        $res = $finder->files()->in(APP . 'Plugin' . DS . 'MapModule' . DS . 'webroot' . DS . 'img' . DS . 'backgrounds')->exclude('thumb');

        $backgrounds = [];
        /** @var \Symfony\Component\Finder\SplFileInfo $file */
        foreach ($finder as $file) {
            $backgrounds[] = [
                'image' => $file->getFilename(),
                'path' => sprintf('/map_module/img/backgrounds/%s', $file->getFilename()),
                'thumbnail' => sprintf('/map_module/img/backgrounds/thumb/thumb_%s', $file->getFilename()),
            ];
        }

        $this->set('backgrounds', $backgrounds);
        $this->set('_serialize', ['backgrounds']);
    }

}
