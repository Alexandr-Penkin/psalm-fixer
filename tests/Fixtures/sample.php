<?php

function processInt(int $alreadyInt): int {
    return (int)$alreadyInt;
}

/** @param object|null $obj */
function getName(?object $obj): ?string {
    return $obj->getName();
}

function checkValid(bool $isValid): void {
    if ($isValid === true) {
        echo 'valid';
    }
}
