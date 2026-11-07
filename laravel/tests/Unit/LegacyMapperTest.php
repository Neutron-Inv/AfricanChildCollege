<?php

namespace Tests\Unit;

use App\Services\Legacy\LegacyMapper;
use PHPUnit\Framework\TestCase;

class LegacyMapperTest extends TestCase
{
    public function test_class_names_are_parsed_into_level_and_arm(): void
    {
        $this->assertSame(['level' => 'JS1', 'arm' => 'Diamond', 'unusual' => false], LegacyMapper::className('JS1 Diamond'));
        $this->assertSame(['level' => 'SS2', 'arm' => 'Gold', 'unusual' => false], LegacyMapper::className(' SSS 2   Gold '));
        $this->assertSame(['level' => 'JS3', 'arm' => 'Ruby', 'unusual' => true], LegacyMapper::className('JSS3 Ruby'));
        $this->assertSame(['level' => 'Nursery 1', 'arm' => 'Joy', 'unusual' => false], LegacyMapper::className('Nursery 1 Joy'));
        $this->assertSame(['level' => 'Primary 4', 'arm' => 'Success', 'unusual' => false], LegacyMapper::className('Primary 4 Success'));
        $this->assertSame(['level' => 'Reception', 'arm' => '', 'unusual' => false], LegacyMapper::className('Reception'));
        $this->assertSame(['level' => 'JSS 1', 'arm' => '', 'unusual' => false], LegacyMapper::className('JSS 1'));
        $this->assertSame(['level' => 'SSS 2', 'arm' => '', 'unusual' => false], LegacyMapper::className('SSS 2'));
        $this->assertSame(['level' => 'Creche', 'arm' => '', 'unusual' => false], LegacyMapper::className('Creche'));
        $this->assertSame(['level' => 'Preparatory', 'arm' => '', 'unusual' => false], LegacyMapper::className('Preparatory'));
        $this->assertSame('Unknown', LegacyMapper::className('')['arm']);
    }

    public function test_exam_names_are_parsed_into_session_and_term(): void
    {
        $this->assertSame(
            ['session' => '2023/2024', 'start_year' => 2023, 'end_year' => 2024, 'sequence' => 2, 'term_name' => 'Second Term'],
            LegacyMapper::examName('2023/2024 second term'),
        );
        $this->assertNull(LegacyMapper::examName('Mock exam'));
    }

    public function test_value_helpers(): void
    {
        $this->assertSame('a@b.co', LegacyMapper::email(' A@B.co '));
        $this->assertSame('', LegacyMapper::email('not-an-email'));
        $this->assertSame('Male', LegacyMapper::gender('m'));
        $this->assertSame('Female', LegacyMapper::gender('FEMALE'));
        $this->assertSame('Other', LegacyMapper::gender(' Other '));
        $this->assertNull(LegacyMapper::date('0000-00-00'));
        $this->assertSame('2023-01-09', LegacyMapper::date('2023-01-09 10:00:00'));
        $this->assertSame(4, LegacyMapper::trait('4'));
        $this->assertNull(LegacyMapper::trait('0'));
        $this->assertNull(LegacyMapper::trait('9'));
        $this->assertSame(0.0, LegacyMapper::number('abc'));
    }
}
