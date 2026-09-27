<?php

namespace Tests\Feature;

use App\Exceptions\DuplicateValue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PDOException;
use Tests\TestCase;

/**
 * The safety net under the forms.
 *
 * Every screen checks the uniqueness it can before saving, so a violation that
 * still reaches the database is a race or a path nobody thought of. It comes
 * back as a validation error either way, never as a page of stack trace.
 */
class DuplicateValueTest extends TestCase
{
    protected function violation(string $driverMessage, string $sql): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException('mysql', $sql, [], new PDOException($driverMessage));
    }

    public function test_the_column_and_value_are_read_off_what_mysql_said(): void
    {
        // The report that brought this up, verbatim.
        $exception = $this->violation(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'Cairo' for key 'sites_code_unique'",
            'insert into sites (name, code, city, description, is_active, updated_at, created_at) values (?, ?, ?, ?, ?, ?, ?)',
        );

        $this->assertSame('sites', DuplicateValue::table($exception));
        $this->assertSame('code', DuplicateValue::column($exception));
        $this->assertSame('Cairo', DuplicateValue::value($exception));

        // A key over two columns, and one the table name cannot be peeled off.
        $composite = $this->violation(
            "Duplicate entry '3-A-01' for key 'workstations_floor_id_name_unique'",
            'insert into `workstations` (`floor_id`, `name`) values (?, ?)',
        );

        $this->assertSame('floor_id_name', DuplicateValue::column($composite));

        $unknown = $this->violation('some other trouble entirely', 'insert into sites (name) values (?)');

        $this->assertNull(DuplicateValue::column($unknown));
    }

    public function test_sqlites_own_wording_is_understood_too(): void
    {
        $exception = $this->violation(
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: sites.code',
            'insert into "sites" ("name", "code") values (?, ?)',
        );

        $this->assertSame('code', DuplicateValue::column($exception));
    }

    public function test_it_becomes_a_validation_error_on_the_field_that_holds_the_value(): void
    {
        $exception = DuplicateValue::asValidation($this->violation(
            "Duplicate entry 'Cairo' for key 'sites_code_unique'",
            'insert into sites (name, code) values (?, ?)',
        ));

        $this->assertInstanceOf(ValidationException::class, $exception);
        // Forms keep their state under "data"; anything else reads the column.
        $this->assertSame(['data.code', 'code'], array_keys($exception->errors()));
        $this->assertStringContainsString('“Cairo” is already used by another record', $exception->errors()['code'][0]);
        $this->assertStringContainsString('code has to be unique', $exception->errors()['code'][0]);
    }

    public function test_a_request_that_hits_one_comes_back_as_a_validation_error(): void
    {
        Route::middleware('web')->post('/_duplicate-probe', fn () => throw $this->violation(
            "Duplicate entry 'Cairo' for key 'sites_code_unique'",
            'insert into sites (name, code) values (?, ?)',
        ));

        // Not a 500: the browser is sent back with the message on the field.
        $this->from('/_probe-from')
            ->post('/_duplicate-probe')
            ->assertRedirect('/_probe-from')
            ->assertSessionHasErrors(['code']);

        $this->postJson('/_duplicate-probe')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }
}
