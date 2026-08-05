import './bootstrap';
import './navigation';
import './enhance';

import Alpine from 'alpinejs';
import lineItems from './line-items';
import opnameCounter from './opname-counter';
import packingStation from './packing-station';
import resiLookup from './resi-lookup';
import returnStation from './return-station';
import documentScanner from './scanner';

window.Alpine = Alpine;

// Didaftarkan sebelum start() agar komponen hasil swap AJAX ikut dikenali.
Alpine.data('lineItems', lineItems);
Alpine.data('opnameCounter', opnameCounter);
Alpine.data('packingStation', packingStation);
Alpine.data('resiLookup', resiLookup);
Alpine.data('returnStation', returnStation);
Alpine.data('documentScanner', documentScanner);

Alpine.start();
