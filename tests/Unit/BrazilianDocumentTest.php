<?php

namespace Tests\Unit;

use App\Support\BrazilianDocument;
use PHPUnit\Framework\TestCase;

class BrazilianDocumentTest extends TestCase
{
    public function test_validates_and_identifies_cpf_and_cnpj(): void
    {
        $this->assertTrue(BrazilianDocument::isValid('529.982.247-25'));
        $this->assertSame(BrazilianDocument::CPF, BrazilianDocument::type('52998224725'));

        $this->assertTrue(BrazilianDocument::isValid('11.222.333/0001-81'));
        $this->assertSame(BrazilianDocument::CNPJ, BrazilianDocument::type('11222333000181'));

        $this->assertFalse(BrazilianDocument::isValid('000.000.000-00'));
        $this->assertFalse(BrazilianDocument::isValid('11.111.111/1111-11'));
    }
}
