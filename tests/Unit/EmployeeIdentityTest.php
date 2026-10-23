<?php

namespace Tests\Unit;

use App\Services\Dashboard\EmployeeIdentity;
use App\Services\Dashboard\EmployeeRegulation;
use PHPUnit\Framework\TestCase;

class EmployeeIdentityTest extends TestCase
{
    public function testOnlyStoredIdentityPathsAreAccepted()
    {
        $hash = str_repeat('a', 64);
        $this->assertTrue(EmployeeIdentity::validPath('branch-expenses/employee-identity/21/front-'.$hash.'.jpg'));
        $this->assertTrue(EmployeeIdentity::validPath('branch-expenses/employee-identity/7/back-'.$hash.'.png'));
        foreach ([null, '', 'branch-expenses/x.jpg', 'branch-expenses/employee-identity/21/../front-'.$hash.'.jpg',
            'branch-expenses/employee-identity/0/front-'.$hash.'.jpg', 'branch-expenses/employee-identity/21/side-'.$hash.'.jpg',
            'branch-expenses/employee-identity/21/front-'.$hash.'.pdf', 'branch-expenses\\employee-identity/21/front-'.$hash.'.jpg'] as $path) {
            $this->assertFalse(EmployeeIdentity::validPath($path), (string) $path);
        }
    }

    public function testListingHidesStoragePathsAndShowsStatus()
    {
        $row = ['id' => 5, 'name' => 'أبو النجا', 'id_front_path' => 'branch-expenses/employee-identity/5/front-'.str_repeat('b', 64).'.jpg',
            'id_front_hash' => str_repeat('b', 64), 'id_front_mime' => 'image/jpeg', 'id_back_path' => null, 'id_back_hash' => null,
            'id_back_mime' => null, 'regulation_version' => EmployeeRegulation::VERSION, 'regulation_accepted_at' => '2026-10-23 12:00:00',
            'regulation_accepted_by' => 1];
        $item = EmployeeIdentity::present($row);
        foreach (['id_front_path', 'id_front_hash', 'id_front_mime', 'id_back_path', 'id_back_hash', 'id_back_mime', 'regulation_accepted_by'] as $key) {
            $this->assertArrayNotHasKey($key, $item);
        }
        $this->assertSame(['front' => true, 'back' => false], $item['identity']);
        $this->assertTrue($item['regulation']['accepted']);
        $this->assertTrue($item['regulation']['current']);
        $this->assertSame('أبو النجا', $item['name']);
    }

    public function testOlderRegulationVersionNeedsNewAcceptance()
    {
        $old = EmployeeRegulation::status(['regulation_version' => '2020-01-01', 'regulation_accepted_at' => '2020-01-01 00:00:00', 'regulation_accepted_by' => 1]);
        $this->assertTrue($old['accepted']);
        $this->assertFalse($old['current']);
        $none = EmployeeRegulation::status([]);
        $this->assertFalse($none['accepted']);
        $this->assertFalse($none['current']);
        $this->assertSame(EmployeeRegulation::VERSION, $none['current_version']);
        $incomplete = EmployeeRegulation::status(['regulation_version' => EmployeeRegulation::VERSION]);
        $this->assertFalse($incomplete['accepted']);
        $this->assertFalse($incomplete['current']);
    }

    public function testRegulationTextIsComplete()
    {
        $this->assertSame('2026-10-23', EmployeeRegulation::VERSION);
        $this->assertSame('7c4dbe0964187ab095748bc5e989645bd9387545d8365d7982d7e19f9f071419', EmployeeRegulation::sha256());
        $sections = EmployeeRegulation::sections();
        $this->assertGreaterThanOrEqual(14, count($sections));
        foreach ($sections as $section) {
            $this->assertNotSame('', trim($section['title']));
            $this->assertNotEmpty($section['items']);
        }
    }
}
