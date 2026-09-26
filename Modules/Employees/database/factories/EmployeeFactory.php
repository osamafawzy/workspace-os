<?php

namespace Modules\Employees\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Employees\Models\EmergencyContact;
use Modules\Employees\Models\Employee;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'oid' => (string) $this->faker->unique()->numberBetween(1000000, 9999999),
            'employee_number' => 'EMP'.$this->faker->unique()->numerify('#####'),
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'mobile' => '+20 10 '.$this->faker->numerify('#### ####'),
            'job_title' => $this->faker->randomElement(['Customer Service Advisor', 'Team Leader', 'IT Technician', 'Quality Analyst']),
            'status' => EmployeeStatus::Active,
            'joined_at' => $this->faker->dateTimeBetween('-5 years', '-1 month'),
        ];
    }

    /** With a national ID, an address and both emergency contacts. */
    public function withPersonalDetails(): static
    {
        return $this->state(fn (): array => [
            'national_id' => $this->faker->unique()->numerify('2##############'),
            'address' => $this->faker->address(),
        ])->afterCreating(function (Employee $employee): void {
            foreach (EmergencyContact::SLOTS as $slot) {
                $employee->emergencyContacts()->create([
                    'slot' => $slot,
                    'name' => $this->faker->name(),
                    'relationship' => $this->faker->randomElement(['Mother', 'Father', 'Spouse', 'Sibling']),
                    'phone' => '+20 11 '.$this->faker->numerify('#### ####'),
                    'address' => $this->faker->address(),
                ]);
            }
        });
    }

    public function left(): static
    {
        return $this->state(fn (): array => [
            'status' => EmployeeStatus::Left,
            'left_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ]);
    }
}
