<?php
namespace ZoteroImportplus;

return [
    'api_adapters' => [
        'invokables' => [
            'zotero_importplus' => Api\Adapter\ZoteroImportplusAdapter::class,
            'zotero_importplus_items' => Api\Adapter\ZoteroImportplusItemAdapter::class,
        ],
    ],
    'entity_manager' => [
        'mapping_classes_paths' => [
            dirname(__DIR__) . '/src/Entity',
        ],
        'proxy_paths' => [
            dirname(__DIR__) . '/data/doctrine-proxies',
        ],
    ],
    'controllers' => [
        'factories' => [
            'ZoteroImportplus\Controller\Index' => Service\IndexControllerFactory::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack'      => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label'      => 'Zotero Import Plus', // @translate
                'route'      => 'admin/zotero-importplus',
                'resource'   => 'ZoteroImportplus\Controller\Index',
                'pages'      => [
                    [
                        'label' => 'Import', // @translate
                        'route'    => 'admin/zotero-importplus',
                        'action' => 'import',
                        'resource' => 'ZoteroImportplus\Controller\Index',
                    ],
                    [
                        'label' => 'Past Imports', // @translate
                        'route'    => 'admin/zotero-importplus/default',
                        'action' => 'browse',
                        'resource' => 'ZoteroImportplus\Controller\Index',
                    ],
                ],
            ],
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'zotero-importplus' => [
                        'type' => 'Literal',
                        'options' => [
                            'route' => '/zotero-importplus',
                            'defaults' => [
                                '__NAMESPACE__' => 'ZoteroImportplus\Controller',
                                'controller' => 'index',
                                'action' => 'import',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'id' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/:import-id[/:action]',
                                    'constraints' => [
                                        'import-id' => '\d+',
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                    ],
                                ],
                            ],
                            'default' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/:action',
                                    'constraints' => [
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
];
