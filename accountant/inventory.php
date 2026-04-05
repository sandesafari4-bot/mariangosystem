<?php
include '../config.php';
checkAuth();
header('Location: inventory_payments.php');
exit();
