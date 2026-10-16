<?php
// El acceso legacy utiliza el login único del POS, con límites y cajeros activos.
header('Cache-Control: no-store');
header('Location: pos.php', true, 303);
exit;
