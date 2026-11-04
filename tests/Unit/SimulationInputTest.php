<?php
declare(strict_types=1);
namespace LibreSign\Pricing\Tests\Unit;
use LibreSign\Pricing\Domain\SimulationInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class SimulationInputTest extends TestCase
{
    public static function invalidInputs(): iterable
    {
        $base = ['model'=>'cloud', 'parameter_version'=>'synthetic-v1', 'usage'=>['documents'=>0,'storage_gb'=>0,'service_hours'=>0]];
        yield 'unknown model' => [[...$base,'model'=>'bogus']];
        yield 'unsupported publish' => [[...$base,'publish'=>true]];
        yield 'negative quantity' => [[...$base,'usage'=>[...$base['usage'],'documents'=>-1]]];
        yield 'float quantity' => [[...$base,'usage'=>[...$base['usage'],'documents'=>1.5]]];
        yield 'missing quantity' => [[...$base,'usage'=>['documents'=>0,'storage_gb'=>0]]];
        yield 'non decimal candidate' => [[...$base,'candidate_price'=>3.5]];
        yield 'invalid precision' => [[...$base,'candidate_price'=>'1.005']];
        yield 'invalid infrastructure usage' => [[...$base,'model'=>'customer_infrastructure','usage'=>[...$base['usage'],'documents'=>1]]];
    }
    #[DataProvider('invalidInputs')]
    public function testInvalid(array $body): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SimulationInput::fromArray($body);
    }
}
