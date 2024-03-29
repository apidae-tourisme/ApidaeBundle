/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

require('bootstrap');

// any CSS you import will output into a single css file (app.css in this case)
import './styles/app.scss';

// start the Stimulus application
import './bootstrap';

import dateformat from 'dateformat';

import 'chosen-js/chosen.jquery.min.js';
import 'chosen-js/chosen.min.css';

import 'datatables/media/css/jquery.dataTables.min.css';
import 'datatables/media/js/jquery.dataTables';
import './form.js';
import './menu.js';
import './submenubar.js';
import './tables.js';
import './tabs.js';
import './taches.js';
import './utils.js';

const $ = require('jquery');
global.$ = global.jQuery = $;