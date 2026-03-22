<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Cadenas de idioma para tool_csvtocourse (Español).
 *
 * @package    tool_csvtocourse
 * @copyright  2026 Román Huerta Manrique <lastent@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'CSV a Curso';
$string['csvtocourse:use'] = 'Usar CSV a Curso';
$string['csvtocourse'] = 'CSV a Curso';
$string['csvfile'] = 'Archivo CSV';
$string['csvfile_help'] = 'Sube un archivo CSV con la estructura del curso. El archivo debe tener las siguientes columnas: section_id, section_name, activity_type, activity_name, content_text, source_url_path';
$string['coursefullname'] = 'Nombre completo del curso';
$string['courseshortname'] = 'Nombre corto del curso';
$string['coursecategory'] = 'Categoría del curso';
$string['createcourse'] = 'Crear curso';
$string['coursecreated'] = '¡Curso creado exitosamente! Redirigiendo...';
$string['emptycsv'] = 'El archivo CSV está vacío o no tiene filas válidas.';
$string['invalidcsv'] = 'El archivo CSV no es válido. Por favor, verifica el formato y las columnas requeridas.';
$string['restorefailed'] = 'La restauración del curso falló. Por favor, revisa el contenido del CSV e intenta de nuevo.';
$string['shortnametaken'] = 'Este nombre corto ya está siendo usado por otro curso.';
$string['csvformat_help'] = 'El archivo CSV debe contener estas columnas: <strong>section_id</strong>, <strong>section_name</strong>, <strong>activity_type</strong>, <strong>activity_name</strong>, <strong>content_text</strong>, <strong>source_url_path</strong>.<br>
Columnas de fecha opcionales: <strong>date_start</strong>, <strong>date_end</strong>, <strong>date_cutoff</strong> (formato: AAAA-MM-DD o AAAA-MM-DD HH:MM). Dejar en blanco para valores predeterminados.<br>
Las columnas de fecha aplican a: <em>forum</em> (entrega/cierre), <em>assign</em> (apertura/entrega/cierre), <em>quiz</em> (apertura/cierre), <em>feedback</em> (apertura/cierre).<br>
Tipos de actividad soportados: <em>label, url, resource, page, forum, assign, quiz, feedback</em>.<br>
La sección 0 es la sección General. Deja activity_type vacío para filas que solo definen secciones.';
$string['downloadsample'] = 'Descargar CSV de ejemplo';
$string['privacy:metadata'] = 'El plugin CSV a Curso no almacena datos personales.';
