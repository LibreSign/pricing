<?php
// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace LibreSign\Pricing;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;
    public function getProjectDir(): string { return dirname(__DIR__); }
}
