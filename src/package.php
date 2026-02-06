<?php

namespace staticphp;

interface package
{
    public function getName(): string;

    public function getFpmConfig(): array;

    public function getDebuginfoFpmConfig(): array;

    public function getFpmExtraArgs(): array;

    public function getLicense(): string;
    public function getDescription(): string;
}
