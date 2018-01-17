<?php

require_once('EngagingNetworksDataExport/DataExport.php');

use EngagingNetworksDataExport\DataExport;

$dataExport = new DataExport();

print_r($dataExport->handle());
