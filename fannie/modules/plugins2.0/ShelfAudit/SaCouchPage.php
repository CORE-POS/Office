<?php
/*******************************************************************************

Copyright 2017 Whole Foods Co-op

This file is part of CORE-POS.

Use CouchDB + PouchDB as a data store for counting data so
devices can drop off and reconnect to the network while scanning
without any loss of data. Requires a CouchDB instance with two
databases named "items" and "counts".

The "items" database is to look up names and case sizes of items.
It needs to have this view:
    Path: /items/_design/upc/_view/upc
    Code:
        function (doc) {
          if (doc.upc) {
            emit(doc.upc, doc);
          }
        }

The "counts" database stores counted total for each item 
sub-divided [optionally] by store and section IDs.
It needs to have this view:
    Path: /counts/_design/entries/_view/by_compostie
    Code:
        function (doc) {
          if (doc.cleared == 0 && doc.upc && doc.storeID && doc.sectionID) {
            emit({upc:doc.upc, storeID:doc.storeID, sectionID:doc.sectionID}, doc);
          }
        } 

Since the querying is through views I think this works in CouchDB 1.x
but I have only tried it with 2.0.

Still missing:
* Method or task to fully populate CouchDB "items" database
* Reporting on CouchDB "counts" database (or a sync back to MySQL for reporting)

Don't use this for real inventory.
*********************************************************************************/

include(dirname(__FILE__).'/../../../config.php');
if (!class_exists('FannieAPI')) {
    include_once($FANNIE_ROOT.'classlib2.0/FannieAPI.php');
}

class SaCouchPage extends FannieRESTfulPage 
{
    private $section=0;
    protected $current_item_data=array();
    private $linea_ios_mode = false;

    public $page_set = 'Plugin :: Shelf Audit';
    public $description = '[Handheld] is an interface for scanning and entering quantities on
    hand using a handheld device.';
    protected $title = 'ShelfAudit Inventory';
    public $discoverable = false;
    protected $header = '';

    private function linea_support_available()
    {
        if (file_exists($this->config->get('ROOT') . 'src/javascript/linea/cordova-2.2.0.js')
        && file_exists($this->config->get('ROOT') . 'src/javascript/linea/ScannerLib-Linea-2.0.0.js')) {
            return true;
        }

        return false;
    }

    protected function setSection()
    {
        if (!isset($_SESSION['SaPluginSection'])) {
            $_SESSION['SaPluginSection'] = 0;
        }
        $section = FormLib::get('section', false);
        if ($section !== false) {
            $_SESSION['SaPluginSection'] = $section;
        } else {
            $section = $_SESSION['SaPluginSection'];
        }

        return $section;
    }

    function preprocess()
    {
        /**
          Store session in browser section.
        */
        if (ini_get('session.auto_start')==0 && !headers_sent() && php_sapi_name() != 'cli' && session_id() == '') {
            @session_start();
        }
        $this->section = $this->setSection();

        $this->linea_ios_mode = $this->linea_support_available();
        if ($this->linea_ios_mode){
            $this->add_script($this->config->get('URL').'src/javascript/linea/cordova-2.2.0.js');
            $this->add_script($this->config->get('URL').'src/javascript/linea/ScannerLib-Linea-2.0.0.js');
        }
        
        return parent::preprocess();
    }

    function css_content()
    {
        ob_start();
        ?>
input#cur_qty {
    font-size: 135%;
    font-weight: bold;
}
input.focused {
    background: #ffeebb;
}
        <?php
        return ob_get_clean();
    }

    function javascript_content()
    {
        ob_start();
        ?>
function doubleBeep() {
    if (typeof cordova.exec != 'function') {
        setTimeout('doubleBeep()', 500);
    } else {
        if (Device) {
            Device.playSound([500, 100, 0, 100, 1000, 100, 0, 100, 500, 100]);
        }
    }
}

        <?php if ($this->linea_ios_mode){ ?>
Device = new ScannerDevice({
    barcodeData: function (data, type){
        var upc = data.substring(0,data.length-1);
        if ($('#upc_in').length > 0){
            $('#upc_in').val(upc);
            $('#goBtn').click();
        }
    },
    magneticCardData: function (track1, track2, track3){
    },
    magneticCardRawData: function (data){
    },
    buttonPressed: function (){
    },
    buttonReleased: function (){
    },
    connectionState: function (state){
    }
});
ScannerDevice.registerListener(Device);

if (typeof WebBarcode == 'object') {
    WebBarcode.onBarcodeScan(function(ev) {
        var data = ev.value;
        var upc = data.substring(0,data.length-1);
        $('#upc_in').val(upc);
        $('#goBtn').click();
    });
}
        <?php } ?>
function upcCallback() {
    const upc = $('#upc_in').val();
    $('#upc_in').val('');
    const store = $('#store').val();
    const section = $('input[name=section]:checked').val();
    Couch.getItem(upc, function(item) {
        var caseSizes = [1];
        if (item.units > 1) caseSizes.push(item.units);
        Couch.getCount(upc, store, section, function (count) {
            var para = $('<p />')
                .append($('<span />').html(item.upc + ' ' + item.brand + ' ' + item.description))
                .append($('<br />'))
                .append($('<span id="old-qty" class="collapse" />').html(count.quantity))
                .append($('<span id="live-qty" />').html('<strong>Current Qty:</strong> ' + count.quantity));
            var div = $('<div class="form-group form-inline" />')
                .append(
                    $('<input type="number" min="-9999" max="9999" step="0.01" class="form-control input-lg" tabindex="-2" id="cur_qty" />')
                        .focus(function() {
                            handheld.paintFocus('cur_qty');
                            $(this).select();
                        }).keyup(function (ev) {
                        }).keydown(handheld.catchTab)
                ).append($('<input type="hidden" id="cur_upc" />').val(upc));
            for (var i=0; i<caseSizes.length; i++) {
                div = div.append(
                        $('<button tabindex="-1" type="button" class="btn btn-success btn-lg" />').html('+'+caseSizes[i])
                            .click(function() {})
                    ).append(
                        $('<button tabindex="-1" type="button" class="btn btn-danger btn-lg" />').html('-'+caseSizes[i])
                            .click(function() {})
                    );
            }

            $('#qtyArea').html('').append(para).append(div)
            $('#cur_qty').focus();
        });
    });
}
        <?php
        return ob_get_clean();
    }

    protected function upcForm($store)
    {
        ?>
<form onsubmit="upcCallback(); return false;" id="upcScanForm">
<a href="SaMenuPage.php">Menu</a>
 - Store # <?php echo $store; ?>
<input type="hidden" name="store" id="store" value="<?php echo ((int)$store); ?>" />
<label>
    <input tabindex="-1" type="radio" name="section" value=0 <?php echo $_SESSION['SaPluginSection']==0 ? 'checked' : ''; ?>/> Backstock
</label>
<label>
    <input tabindex="-1" type="radio" name="section" value=1 <?php echo $_SESSION['SaPluginSection']==1 ? 'checked' : ''; ?>/> Floor
</label>
<br />
<div class="form-group form-inline">
    <div class="input-group">
        <label class="input-group-addon">UPC</label>
        <input type="number" size="10" name="id" id="upc_in" 
            onfocus="handheld.paintFocus('upc_in');"
            class="focused form-control" tabindex="1"
        />
    </div>
    <button type="submit" class="btn btn-success" tabindex="-1" id="goBtn">Go</button>
</div>
</form>
        <?php
    }

    function get_view()
    {
        ob_start();
        $store = COREPOS\Fannie\API\lib\Store::getIdByIp();
        $this->upcForm($store);
        echo '<div id="qtyArea"></div>';
        $this->addScript('js/handheld.js');
        $this->addScript('js/couch.js');
        $this->addScript('node_modules/pouchdb/dist/pouchdb.min.js');
        $this->addOnloadCommand("Couch.init('10.2.2.2', 'items', 'counts');\n");
        $this->addOnloadCommand("\$('#upc_in').focus();\n");

        return ob_get_clean();
    }
}

FannieDispatch::conditionalExec();

