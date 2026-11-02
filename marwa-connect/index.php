<?php
// Accès de secours lorsque mod_rewrite est désactivé sur WampServer.
header('Location: public/index.php/', true, 302);
exit;
