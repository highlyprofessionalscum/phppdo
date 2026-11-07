<?php

use PhpPdo\PDO;
use PhpPdo\SqlLiteral;

return function () {
    assert_same("'O''Brien'", SqlLiteral::render("O'Brien", PDO::PARAM_STR));
    assert_same('NULL', SqlLiteral::render(null, PDO::PARAM_STR));
    assert_same('NULL', SqlLiteral::render('x', PDO::PARAM_NULL));
    assert_same('42', SqlLiteral::render('42', PDO::PARAM_INT));
    assert_same('0', SqlLiteral::render(false, PDO::PARAM_BOOL));
    assert_same('1', SqlLiteral::render(true, PDO::PARAM_BOOL));
    assert_same("'42'", SqlLiteral::render(42, PDO::PARAM_STR));
    assert_same('1.5', SqlLiteral::formatFloat(1.5));
};
