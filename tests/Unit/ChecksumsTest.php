<?php

namespace EduLazaro\Laranon\Tests\Unit;

use EduLazaro\Laranon\Support\Checksums;
use PHPUnit\Framework\TestCase;

class ChecksumsTest extends TestCase
{
    public function test_dni_validates_control_letter(): void
    {
        $this->assertTrue(Checksums::dni('12345678Z'));
        $this->assertTrue(Checksums::dni('12345678-Z'));
        $this->assertFalse(Checksums::dni('12345678A'));
        $this->assertFalse(Checksums::dni('1234567Z'));
    }

    public function test_nie_validates_control_letter(): void
    {
        $this->assertTrue(Checksums::nie('X1234567L'));
        $this->assertFalse(Checksums::nie('X1234567Z'));
    }

    public function test_cif_validates_control(): void
    {
        $this->assertTrue(Checksums::cif('A58818501'));
        $this->assertFalse(Checksums::cif('A58818502'));
    }

    public function test_iban_validates_mod97(): void
    {
        $this->assertTrue(Checksums::iban('ES9121000418450200051332'));
        $this->assertTrue(Checksums::iban('ES91 2100 0418 4502 0005 1332'));
        $this->assertTrue(Checksums::iban('GB29NWBK60161331926819'));
        $this->assertFalse(Checksums::iban('ES9121000418450200051333'));
    }

    public function test_luhn(): void
    {
        $this->assertTrue(Checksums::luhn('4111111111111111'));
        $this->assertFalse(Checksums::luhn('4111111111111112'));
    }

    public function test_ccc_validates_both_control_digits(): void
    {
        $this->assertTrue(Checksums::ccc('21000418450200051332'));
        $this->assertFalse(Checksums::ccc('21000418440200051332'));
    }

    public function test_nss_validates_mod97_control(): void
    {
        $control = str_pad((string) Checksums::mod97('2812345678'), 2, '0', STR_PAD_LEFT);

        $this->assertTrue(Checksums::nss('28 12345678 ' . $control));
        $this->assertFalse(Checksums::nss('28 12345678 ' . str_pad((string) ((Checksums::mod97('2812345678') + 1) % 97), 2, '0', STR_PAD_LEFT)));
    }
}
