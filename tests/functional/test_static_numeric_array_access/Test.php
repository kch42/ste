<?php

namespace tests\functional\test_static_numeric_array_access;

use kch42\ste\STECore;
use tests\functional\BaseTest;

class Test extends BaseTest
{
    protected function getDirectory(): string
    {
        return __DIR__;
    }

    protected function setUpSte(STECore $ste): void
    {
        $ste->set_var_by_name("foo", array(
            "foo",
            "bar",
            "baz",
        ));
    }
}