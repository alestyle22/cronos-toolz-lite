<?php
/**
 * ╔══════════════════════════════════════════════╗
 * ║  TOOLZ PLUGIN — Backup del sitio            ║
 * ║  ID:      backup                            ║
 * ║  Version: 1.0                               ║
 * ║  Author:  Toolz                             ║
 * ╚══════════════════════════════════════════════╝
 *
 * Genera backups del sitio y la base de datos.
 * Modo manual: descarga ZIP al navegador.
 * Modo automático: guarda ZIP en el servidor.
 */

// ── METADATA (leída por el sistema de plugins) ──────────────
return [
    'id'          => 'backup',
    'name'        => 'Backup del sitio',
    'description' => 'Genera backups automáticos o manuales del sitio y la base de datos.',
    'icon'        => 'fa-hard-drive',
    'version'     => '1.0',
    'author'      => 'Toolz',
    'config_fields' => [
        ['key'=>'auto_enabled',   'label'=>'Backup automático',           'type'=>'toggle', 'default'=>false],
        ['key'=>'frequency',      'label'=>'Frecuencia',                  'type'=>'select', 'options'=>['diario','semanal','mensual'], 'default'=>'semanal'],
        ['key'=>'time',           'label'=>'Hora del backup',             'type'=>'time',   'default'=>'03:00'],
        ['key'=>'keep',           'label'=>'Backups a conservar',         'type'=>'number', 'min'=>1, 'max'=>30, 'default'=>5],
        ['key'=>'include_db',     'label'=>'Incluir base de datos',       'type'=>'toggle', 'default'=>true],
        ['key'=>'include_files',  'label'=>'Incluir archivos del sitio',  'type'=>'toggle', 'default'=>true],
        ['key'=>'path',           'label'=>'Carpeta destino en servidor', 'type'=>'text',   'default'=>'/toolz/plugins/backup/storage/', 'span'=>2],
    ],
    // Acciones extra que aparecen en la tarjeta del plugin
    'actions' => [
        ['id'=>'manual', 'label'=>'Backup manual',       'icon'=>'fa-download', 'style'=>'ghost'],
        ['id'=>'save',   'label'=>'Guardar en servidor', 'icon'=>'fa-server',   'style'=>'ghost'],
    ],
];
