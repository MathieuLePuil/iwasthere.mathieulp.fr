<?php

declare(strict_types=1);

// Fix "Failed to set session cookie" when accessed via plain HTTP
$cfg['CookieSameSite'] = 'Lax';
