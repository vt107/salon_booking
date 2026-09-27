<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $prefixes = ['090', '091', '093', '094', '096', '097', '098', '032', '033', '035', '036', '037', '038', '070', '076', '077', '078', '079', '081', '083', '084', '085', '088'];

        $gender = fake()->randomElement([Gender::Female, Gender::Female, Gender::Male]);
        $sex = $gender === Gender::Male ? 'male' : 'female';
        $vi = fake('vi_VN');

        return [
            'name' => "{$vi->lastName()} {$vi->middleName($sex)} {$vi->firstName($sex)}",
            'phone' => fake()->unique()->numerify(fake()->randomElement($prefixes).'#######'),
            'email' => fake()->boolean(70) ? fake()->unique()->safeEmail() : null,
            'gender' => $gender,
            'birthday' => fake()->boolean(40) ? fake()->dateTimeBetween('-50 years', '-18 years') : null,
        ];
    }
}
