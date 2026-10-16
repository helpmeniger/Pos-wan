<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testValidDataIsNormalized(): void
    {
        $v = new Validator(
            ['phone' => '90 12 34 56', 'email' => 'Moussa@Exemple.NE', 'age' => '21', 'ok' => 'on', 'note' => ''],
            ['phone' => 'required|phone', 'email' => 'nullable|email', 'age' => 'required|integer|min:16', 'ok' => 'accepted', 'note' => 'nullable|string']
        );
        $this->assertTrue($v->passes());
        $this->assertSame(['phone' => '+22790123456', 'email' => 'moussa@exemple.ne', 'age' => 21, 'ok' => true, 'note' => null], $v->validated());
    }

    public function testErrorsAreInFrenchWithLabels(): void
    {
        $v = new Validator(['first_name' => ''], ['first_name' => 'required|min:2'], ['first_name' => 'prénom']);
        $this->assertFalse($v->passes());
        $this->assertSame('Le champ « prénom » est obligatoire.', $v->errors()['first_name'][0]);
    }

    public function testPasswordRules(): void
    {
        foreach (['court1' => false, 'sanschiffre' => false, '12345678' => false, 'motdepasse1' => true] as $pwd => $ok) {
            $v = new Validator(['password' => $pwd, 'password_confirmation' => $pwd], ['password' => 'required|password|confirmed']);
            $this->assertSame($ok, $v->passes(), "Mot de passe « {$pwd} »");
        }
    }

    public function testConfirmationMismatch(): void
    {
        $v = new Validator(['password' => 'motdepasse1', 'password_confirmation' => 'autre1234'], ['password' => 'required|password|confirmed']);
        $this->assertFalse($v->passes());
    }

    public function testInDateAndBetween(): void
    {
        $v = new Validator(
            ['type' => 'WORKER', 'd' => '2003-02-30', 'n' => '7'],
            ['type' => 'in:WORKER,CLIENT', 'd' => 'date', 'n' => 'integer|between:1,5']
        );
        $this->assertFalse($v->passes());
        $this->assertArrayHasKey('d', $v->errors());
        $this->assertArrayHasKey('n', $v->errors());
        $this->assertArrayNotHasKey('type', $v->errors());
    }

    public function testArraysAreNotAcceptedAsStrings(): void
    {
        $v = new Validator(['name' => ['x']], ['name' => 'required|string']);
        $this->assertFalse($v->passes());
    }

    public function testUnknownTableInExistsRuleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Validator(['x' => '1'], ['x' => 'exists:payments,id']))->passes();
    }
}
