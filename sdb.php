<?php

/**
 *
 * Amazon SimpleDB admin
 *
 * ------------------------------------------------------------------------
 *
 * Copyright (c) 2014 Dan Cotora
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 * ------------------------------------------------------------------------
 *
 * An simple administration script that allows users to view/edit/remove the data stored in Amazon SimpleDB.
 *
 * The script relies on the AWS SDK v1 <https://github.com/amazonwebservices/aws-sdk-for-php>. You'll need to
 * make sure you have it installed and available in the PHP include path.
 *
 * You'll also need to specify the AWS region in the getConnection() function.
 *
 */

// Set error reporting level
error_reporting(E_ALL ^ E_NOTICE);

// Set the default time zone
date_default_timezone_set('UTC');

// Make sure the AWS sdk.class.php is in your include path or alter the include path using set_include_path()
include('sdk.class.php');

// Set your AWS credentials
CFCredentials::set(array(

     // Default credentials
     'credentials' => array(
         'key'    => '<your-aws-key>',
         'secret' => '<your-aws-secret-key>',
     ),

     // Specify a default credential set to use if there are more than one.
     '@default' => 'credentials'
 ));

// Creates a new SimpleDB connection
function getConnection() {
    $sdb = new AmazonSDB();

    // Specify the AWS region (if it's not us-east-1)
    // e.g. sdb.us-west-2.amazonaws.com
    // $sdb->set_region('<aws-region-endpoint>');

    return $sdb;
}

// Get the SimpleDB domains
function getDomains() {
    $sdb = getConnection();
    return $sdb->get_domain_list();
}

// Get the items from and SimpleDB domain
function getItems($domain) {
    $itemNameKey      = md5('_ItemName_');
    $globalAttributes = array($itemNameKey => array('name' => 'ID', 'size' => 0));
    $globalItems      = array();
    $nextToken        = null;
    $selecCounter     = 0;

    $sdb = getConnection();

    do {
        $selecCounter++;

        $response = $sdb->select('SELECT * FROM `' . $domain . '` LIMIT 2500', array(
            'ConsistentRead' => 'true',
            'NextToken'      => $nextToken
        ));

        $nextToken = $response->body->SelectResult->NextToken;

        if (!$response->isOK()) {
            die('Error while retrieving the items list.');
        }

        foreach ($response->body->SelectResult->Item as $item) {
            $globalAttributes[$itemNameKey]['size'] = max($globalAttributes[$itemNameKey]['size'], strlen($item->Name));

            $itemData = array($itemNameKey => (string)$item->Name);

            foreach ($item->Attribute as $attribute) {
                $key = md5($attribute->Name);

                $counter = 0;
                while (isset($itemData[$key . $counter])) $counter++;

                $itemData[$key . $counter] = (string)$attribute->Value;

                if (!isset($globalAttributes[$key . $counter])) {
                    $globalAttributes[$key . $counter] = array(
                        'name' => (string) $attribute->Name,
                        'size' => max(strlen($attribute->Name), strlen($attribute->Value))
                    );
                } else {
                    $globalAttributes[$key . $counter]['size'] = max($globalAttributes[$key . $counter]['size'], strlen($attribute->Value));
                }
            }

            array_push($globalItems, $itemData);
        }

    } while (!empty($nextToken) && $selecCounter < 11);

    return array(
        'attr'  => $globalAttributes,
        'items' => $globalItems
    );
}

// Remove an item from a SimpleDB domain
function removeItem($domain, $item) {
    $sdb = getConnection();
    return $sdb->delete_attributes($domain, $item);
}

// Update an item in SimpleDB
function updateItem($domain, $item, $attributes) {
    $sdb = getConnection();
    return $sdb->put_attributes($domain, $item, $attributes, true);
}

// Clear all the data from a SimpleDB domain
function clearDomain($domain) {
    $sdb = getConnection();
    $sdb->delete_domain($domain);
    $sdb->create_domain($domain);
}

/**
 * JSON data handlers
 */

// Send out JSON data
function sendJSON($data) {
    header('Content-Type: application/json');
    $data = json_encode($data);
    die($data);
}

// Get the SimpleDB domains
if (isset($_GET['domains'])) {
    $domains = getDomains();
    sendJSON($domains);
}

// Get the SimpleDB items
if (isset($_GET['items'])) {
    $domain = $_GET['domain'];
    $items = getItems($domain);
    sendJSON($items);
}

// Remove an item
if (isset($_GET['remove'])) {
    $domain = $_GET['domain'];
    $item   = $_GET['item'];
    $result = removeItem($domain, $item);
    sendJSON($result);
}

// Update an item
if (isset($_GET['update'])) {
    $domain = $_GET['domain'];
    $item   = $_GET['item'];

    $attributes = array();
    foreach ($_GET as $key => $value) {
        if ($key !== 'field_ID' && strpos($key, 'field_') === 0) {
            $attributeKey = substr($key, 6);
            $attributeKey = str_replace('_', '.', $attributeKey);
            $attributes[$attributeKey] = $value;
        }
    }

    $result = updateItem($domain, $item, $attributes);
    sendJSON($result);
}

// Clear a domain
if (isset($_GET['clear'])) {
    $domain = $_GET['domain'];
    $result = clearDomain($domain);
    sendJSON($result);
}

?>
<!doctype html>
<head>
    <meta charset="utf-8">
    <title>SimpleDB admin</title>
    <link href="//ajax.googleapis.com/ajax/libs/jqueryui/1/themes/base/jquery-ui.css" rel="stylesheet" type="text/css"/>
    <script src="//ajax.googleapis.com/ajax/libs/jquery/1.6.4/jquery.min.js"></script>
    <script src="//ajax.googleapis.com/ajax/libs/jqueryui/1.8.16/jquery-ui.min.js"></script>

    <script type="text/javascript">

        function refreshItems(domain) {

            // Update the domain name
            $('#itemsTitle').html(domain);

            // Show the loader
            $('#itemsList').html('Refreshing...');

            // Get the JSON data for the items
            $.getJSON(document.location.pathname + '?items&domain=' + domain, function(data) {
                var items = '';

                items += '<table><thead><tr>';

                $.each(data.attr, function() {
                    items += '<th>' + this.name + '</th>';
                });

                items += '</tr></thead><tbody>';

                $.each(data.items, function(itemKey, itemValue) {
                    items += '<tr>';

                    $.each(data.attr, function(key, value) {
                        if (itemValue[key]) {
                            items += '<td data-attr="' + value.name + '" title="' + itemValue[key] + '">' + itemValue[key] + '</td>';
                        } else {
                            items += '<td data-attr="' + value.name + '" class="undefined">Undefined</td>';
                        }
                    });

                    items += '</tr>';
                });

                items += '</tbody></table>';

                $('#itemsList').html(items);
            });
        }

        function refreshDomains() {
            // Show the loader for the domains list
            $('#domainsList').html('Refreshing...');

            // Show the loader for the items list
            $('#itemsTitle').html('Refreshing...');
            $('#itemsList').html('');

            // Get the JSON data for the domains list
            $.getJSON(document.location.pathname + '?domains', function(data) {
                var domains = '';

                domains += '<ul>';
                $.each(data, function() {
                    domains += '<li><a href="#">' + this + '</a></li>';
                })
                domains += '</ul>';

                $('#domainsList').html(domains);
                $('#domainsList a:first').click();
            });
        }

        $(function() {
            $('#dialog').dialog({
                autoOpen: false,
                modal: true,
                minWidth: 600,
                buttons: [{
                    text: "Remove this record",
                    click: function() {
                        var itemId = $('#dialog').data('item-id'),
                            domain = $('#itemsTitle').html();

                        var answer = confirm("Are you sure you want to remove the item\n{" + itemId + "}\nfrom " + domain + "?");

                        if (answer) {
                            $.getJSON(document.location.pathname + '?remove&domain=' + domain + '&item=' + itemId, function(data) {
                                $('#dialog').dialog("close");
                                refreshItems(domain);
                            });
                        }
                    }
                },
                {
                    text: "Update this record",
                    click: function() {
                        var itemId = $('#dialog').data('item-id'),
                            domain = $('#itemsTitle').html(),
                            attributes = $('#dialog form').serialize();

                        console.warn(attributes);

                        var answer = confirm("Are you sure you want to update the item\n{" + itemId + "}\nfrom " + domain + "?");

                        if (answer) {
                            $.getJSON(document.location.pathname + '?update&domain=' + domain + '&item=' + itemId + '&' + attributes, function(data) {
                                $('#dialog').dialog("close");
                                refreshItems(domain);
                            });
                        }
                    }
                },
                {
                    text: "Close",
                    click: function() { $(this).dialog("close"); }
                }]
            });

            $('#domainsRefresh').click(function(event) { refreshDomains(); event.preventDefault(); });

            $('#itemsRefresh').click(function(event) { var domain = $('#itemsTitle').html(); refreshItems(domain); event.preventDefault(); });

            $('#clearDomain').click(function(event) {
                event.preventDefault();
                var domain = $('#itemsTitle').html(),
                    answer = confirm("Are you sure you want to remove ALL the data from " + domain + "?");

                if (answer) {
                    $.getJSON(document.location.pathname + '?clear&domain=' + domain, function(data) {
                        console.log('Data cleared...');
                        refreshItems(domain);
                    });
                }
            });

            $('#domainsList').delegate('a', 'click', function(event) {
                var element = $(this),
                    parent = element.parent();

                parent.siblings().removeClass('selected');
                parent.addClass('selected');

                refreshItems(element.html());
                event.preventDefault();
            });

            $('#itemsList').delegate('table tbody tr', 'click', function(event) {
                var dialog = $('#dialog'),
                    list = $('<dl></dl>'),
                    row = $(this),
                    form = $('<form></form>');

                row.children().each(function() {
                    var elm  = $(this),
                        attr = elm.data('attr');

                    if (!elm.hasClass('undefined')) {
                        var value = elm.html(),
                            fieldId = 'field_' + attr + Math.floor(Math.random()*10000+1);
                        if (value.length < 75) {
                            field = $('<dt><label for="' + fieldId + '">' + attr + '</label></dt><dd><input id="' + fieldId + '" type="text" name="field_' + attr + '" value="" /></dd>');
                            field.find('input').val(value);
                            field.appendTo(list);
                        } else {
                            field = $('<dt><label for="' + fieldId + '">' + attr + '</label></dt><dd><textarea id="' + fieldId + '" name="field_' + attr + '"></textarea></dd>');
                            field.find('textarea').val(value);
                            field.appendTo(list);
                        }
                    }
                });

                dialog.empty();
                form.append(list).appendTo(dialog);
                dialog.data('item-id', row.children(':first-child').html()).dialog('open');
            });

            refreshDomains();
        });
    </script>

    <style type="text/css">
        body {
            color: #000;

            font-family: Helvetica, Arial, Sans-serif;
            font-size: 16px;
            padding: 30px 0px 30px 0px;
        }

        dl dt {
            font-weight: bold;
            margin-top: 10px;
        }

        dl dd {
            margin: 0;
        }

        ul {
            list-style: none;
            padding: 0;
        }

        a {
            color: #000;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        table th {
            background: #444;
            color: #fff;
            font-size: 14px;
        }

        table tbody tr:nth-child(even) {
            background: #eaeaea;
        }

        table tbody tr:nth-child(odd) {
            background: #f8f8f8;
        }

        table td {
            font-size: 12px;
            cursor: pointer;
        }

        table td.undefined {
            color: #888;
            font-style:italic
        }

        table td, th {
            white-space: nowrap;
            max-width: 400px;
            overflow: hidden;
            text-overflow: ellipsis;
            padding: 2px 10px 2px 10px;
        }

        #domains {
            padding: 30px;
            border-right: 1px solid #eee;
            width: 250px;
            position: fixed;
            top: 0px;
            left: 0px;
            height: 100%;
            background: rgba(255, 255, 255, 0.9);
        }

        #domainsList {
            font-size: 20px;
            line-height: 1.5em;
            margin-bottom: 20px;
        }

        #domainsList li.selected {
            font-weight: bold;
        }

        #items {
            margin-left: 350px;
        }

        #itemsList {
            margin-bottom: 20px;
        }

        #domainsRefresh, #clearDomain, #itemsRefresh {
            color: #888;
            font-size: 12px;
        }

        #dialog {
            font-size: 12px;
        }

        #dialog input, #dialog textarea {
            width: 100%;
        }

    </style>
</head>

<body>
    <div id="domains">
        <h1>Domains</h1>
        <div id="domainsList">
            <!-- The list of domains -->
        </div>
        <div>
            <a id="domainsRefresh" href="#">Refresh this list</a>
        </div>
    </div>

    <div id="items">
        <h1 id="itemsTitle"></h1>
        <div id="itemsList">
            <!-- The list of items -->
        </div>
        <div>
            <a id="itemsRefresh" href="#">Refresh this list</a> - <a id="clearDomain" href="#">Remove all data from this domain</a>
        </div>
    </div>

    <div id="dialog" title="Data">
        <!-- The edit dialog -->
    </div>
</body>
</html>