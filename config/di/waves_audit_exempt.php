<?php
/**
 * DI wave audit：不要求构造注入的 di_waves 登记类（见 di_permanent_lazy.php SSOT）。
 *
 * @return list<class-string>
 */
return array_keys(require __DIR__ . '/permanent_lazy.php');
