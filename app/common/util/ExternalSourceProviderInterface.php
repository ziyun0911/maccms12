<?php
namespace app\common\util;

interface ExternalSourceProviderInterface
{
    public function getCode();

    public function getLabel();

    public function search($keyword, array $options = []);

    public function fetchRecent(array $options = []);
}

