import './bootstrap';
import './navigation';
import './enhance';

import Alpine from 'alpinejs';
import cameraScanner from './camera-scanner';
import dispatchStation from './dispatch-station';
import lineItems from './line-items';
import opnameCounter from './opname-counter';
import packingStation from './packing-station';
import productBulkEdit from './product-bulk-edit';
import resiLookup from './resi-lookup';
import returnStation from './return-station';
import documentScanner from './scanner';

window.Alpine = Alpine;

// Didaftarkan sebelum start() agar komponen hasil swap AJAX ikut dikenali.
Alpine.data('cameraScanner', cameraScanner);
Alpine.data('dispatchStation', dispatchStation);
Alpine.data('lineItems', lineItems);
Alpine.data('opnameCounter', opnameCounter);
Alpine.data('packingStation', packingStation);
Alpine.data('productBulkEdit', productBulkEdit);
Alpine.data('resiLookup', resiLookup);
Alpine.data('returnStation', returnStation);
Alpine.data('documentScanner', documentScanner);

Alpine.start();
