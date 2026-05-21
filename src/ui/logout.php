<?php
require '/home/collab/data/lib/lib.php';
session_logout();
header('Location: login.php');
exit;
