<?php
$pageTitle = 'Reports';     // capital C, just cosmetic
$activeNav = 'reports';
ob_start();
?>




<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';   // ✅ slash added