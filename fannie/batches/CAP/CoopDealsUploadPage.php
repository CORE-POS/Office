<?php
/*******************************************************************************

    Copyright 2013 Whole Foods Co-op

    This file is part of CORE-POS.

    IT CORE is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    IT CORE is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    in the file license.txt along with IT CORE; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*********************************************************************************/

include(dirname(__FILE__) . '/../../config.php');
if (!class_exists('FannieAPI')) {
    include($FANNIE_ROOT.'classlib2.0/FannieAPI.php');
}

class CoopDealsUploadPage extends \COREPOS\Fannie\API\FannieUploadPage 
{
    public $title = "Fannie - Co+op Deals sales";
    public $header = "Upload Co+op Deals file";

    public $description = '[Co+op Deals Import] loads sales information from Co+op Deals pricing spreadsheets.
    This data can be used to create sales batches.';
    public $themed = true;

    protected $preview_opts = array(
        'upc' => array(
            'display_name' => 'UPC',
            'default' => 7,
            'required' => true
        ),
        'price' => array(
            'display_name' => 'Sale Price',
            'default' => 24,
            'required' => true
        ),
        'abt' => array(
            'display_name' => 'A/B/TPR',
            'default' => 5,
            'required' => true
        ),
        'sku' => array(
            'display_name' => 'SKU',
            'default' => 8,
        ),
        'sub' => array(
            'display_name' => 'Sub',
            'default' => 6,
        ),
        'mult' => array(
            'display_name' => 'Line Notes',
            'default' => 13,
        ),
    );

    private function setupTables($dbc)
    {
        if ($dbc->tableExists('tempCapPrices')){
            $drop = $dbc->prepare("DROP TABLE tempCapPrices");
            $dbc->execute($drop);
        }
        if (!$dbc->tableExists('CoopDealsItems')) {
            $cdi = new CoopDealsItemsModel($dbc);
            $cdi->create();
        }
    }

    private function prepStatements($dbc)
    {
        $upcP = $dbc->prepare('SELECT upc FROM products WHERE upc=? AND inUse=1');
        $skuP = $dbc->prepare('
            SELECT s.upc 
            FROM vendorSKUtoPLU AS s
                INNER JOIN products AS p ON s.vendorID=p.default_vendor_id AND s.upc=p.upc
            WHERE s.sku=?'
        );
        $insP = $dbc->prepare('
            INSERT INTO CoopDealsItems 
                (dealSet, upc, price, abtpr, multiplier)
            VALUES
                (?, ?, ?, ?, ?)');

        return array($upcP, $skuP, $insP);
    }

    private function checkSku($dbc, $upc, $sku, $skuP)
    {
        $look2 = $dbc->execute($skuP, array($sku));
        if ($dbc->num_rows($look2)) {
            $row = $dbc->fetch_row($look2);
            return $row['upc'];
        } else {
            $sku = str_pad($sku, 7, '0', STR_PAD_LEFT);
            $look3 = $dbc->execute($skuP, array($sku));
            if ($dbc->num_rows($look3)) {
                $row = $dbc->fetch_row($look3);
                return $row['upc'];
            }
        }

        return $upc;
    }

    private function dealTypes($type)
    {
        $abt = array();
        if (strstr($type,"A")) {
            $abt[] = "A";
        }
        if (strstr($type,"B")) {
            $abt[] = "B";
        }
        if (strstr($type,"TPR")) {
            $abt[] = "TPR";
        }

        return $abt;
    }

    function process_file($linedata, $indexes)
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('OP_DB'));
        $this->setupTables($dbc);

        $SUB = $this->get_column_index('sub');
        $UPC = $this->get_column_index('upc');
        $SKU = $this->get_column_index('sku');
        $PRICE = $this->get_column_index('price');
        $ABT = $this->get_column_index('abt');
        $MULT = $this->get_column_index('mult');

        $month = FormLib::get('deal-month', 'not specified');
        $delP = $dbc->prepare('DELETE FROM CoopDealsItems WHERE dealSet=?');
        $dbc->execute($delP, array($month));
        list($upcP, $skuP, $insP) = $this->prepStatements($dbc);

        $rm_checks = (FormLib::get_form_value('rm_cds') != '') ? True : False;
        foreach ($linedata as $data) {
            if (!is_array($data)) continue;
            if (count($data) < 14) continue;

            $upc = str_replace("-","",$data[$UPC]);
            $upc = str_replace(" ","",$upc);
            if ($rm_checks)
                $upc = substr($upc,0,strlen($upc)-1);
            $upc = BarcodeLib::padUPC($upc);

            $lookup = $dbc->execute($upcP, array($upc));
            if ($dbc->num_rows($lookup) == 0) {
                $upc = $this->checkSku($dbc, $upc, $data[$SKU], $skuP);
            }
            $mult = 1;
            if ($MULT !== false) {
                $line_notes = $data[$MULT];
                if (preg_match('/(\d+)\/\$(\d+)/', $line_notes, $matches)) {
                    $mult = $matches[1];
                }
            }

            $price = trim($data[$PRICE],"\$");
            foreach ($this->dealTypes($data[$ABT]) as $type){
                $dbc->execute($insP,array($month,$upc,$price,$type,$mult));
            }
        }

        return true;
    }

    function form_content()
    {
        return '<div class="well">Upload a CSV or Excel (XLS, not XLSX) file containing Co+op Deals
            Sale information. The file needs to contain UPCs, sale prices,
            and a column indicating A, B, or TPR (or some combination of the
            three).</div>';
    }

    function preview_content()
    {
        return '
            <label>Month</label><input type="text" name="deal-month" required />
            <label><input type="checkbox" name="rm_cds" checked /> Remove check digits</label>
        ';
    }

    function results_content()
    {
        $ret = "<p>Sales data import complete</p>";
        $ret .= "<p><a href=\"CoopDealsReviewPage.php\">Review data &amp; set up sales</a></p>";
        return $ret;
    }

    public function helpContent()
    {
        return '<p>Default column selections correspond to the
            tab/worksheet that lists all A, B, and TPR items</p>'
            . parent::helpContent();
    }
}

FannieDispatch::conditionalExec();

