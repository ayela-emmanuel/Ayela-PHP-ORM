# AyelaORM - PHP ORM Package

**AyelaORM** is a lightweight PHP Object-Relational Mapping (ORM) package designed to simplify database interactions using PHP's PDO extension. It provides an easy-to-use interface for managing database schemas and objects, dynamically handles table creation and updates, and supports relationships between models, reducing the need for manual schema management and complex SQL queries.

## Table of Contents

1. [Installation](#installation)
2. [Usage](#usage)
   - [Database Setup](#database-setup)
   - [Creating Models](#creating-models)
   - [Saving Data](#saving-data)
   - [Retrieving Data](#retrieving-data)
   - [Updating Data](#updating-data)
   - [Deleting Data](#deleting-data)
3. [Advanced Querying](#advanced-querying)
   - [Simple Conditions](#simple-conditions)
   - [Advanced Conditions](#advanced-conditions)
   - [Relationships (Joins)](#relationships-joins)
   - [One-to-Many and Many-to-Many Relationships](#one-to-many-and-many-to-many-relationships)
4. [Schema Management](#schema-management)
   - [Automatic Schema Updates](#automatic-schema-updates)
   - [Custom SQL Types](#custom-sql-types)
   - [Ignoring Properties](#ignoring-properties)
5. [Data Types and Serialization](#data-types-and-serialization)
6. [Error Handling](#error-handling)
7. [License](#license)

## Installation

Install the package using Composer:

```bash
composer require ayela-emmanuel/ayela-orm
```

Ensure that the `PDO` extension is enabled for your PHP installation (MySQL is the default). Add the necessary namespace `AyelaORM` to your project.

## Usage

### Database Setup

Before using AyelaORM, you must set up the database connection:

```php
use AyelaORM\Database;

Database::setup('localhost', 'database_name', 'username', 'password', false);
```

- **host**: Database server host (e.g., `localhost`).
- **db**: Database name.
- **username**: Database username.
- **password**: Database password.
- **frozen**: If `true`, the database schema will not be checked or updated automatically.

### Creating Models

To create a model that interacts with the database, extend the `DatabaseObject` class:

```php
namespace Models;

use AyelaORM\DatabaseObject;
use AyelaORM\SQLType;
use AyelaORM\SQLIgnore;

class User extends DatabaseObject
{
    public string $db_username;
    public string $db_email;
    public int $db_age;
    public \DateTime $db_created_at;

    #[SQLIgnore]
    public string $password; // Will not be stored in the database

    public function __construct()
    {
        parent::__construct();
        $this->db_created_at = new \DateTime();
    }
}
```

**Notes:**

- **Property Naming**: Properties intended for database storage should be prefixed with `db_`. The prefix is removed when mapping to the database column.
- **Type Handling**: The class infers SQL data types from PHP property types.
- **Attributes**:
  - Use `#[SQLType("...")]` to specify a custom SQL type for a property.
  - Use `#[SQLIgnore]` to exclude properties from the database schema.

Register your model on startup to ensure the schema is up to date:

```php
Models\User::register();
```

### Saving Data

To save a new object to the database:

```php
$user = new Models\User();
$user->db_username = 'john_doe';
$user->db_email = 'john@example.com';
$user->db_age = 30;
$user->password = 'securepassword'; // Will not be saved to the database
$user->save();
```

After saving, the `db_id` property will be populated with the primary key value from the database.

### Retrieving Data

You can retrieve records using several built-in methods.

#### Get All Records

```php
$users = Models\User::list(1, 10); // Retrieve the first 10 users (page 1).
```

#### Get a Record by ID

```php
$user = Models\User::getById(1);
```

#### Get the First Record

```php
$user = Models\User::first();
```

### Updating Data

Update a field of a record by ID:

```php
Models\User::update(1, 'email', 'newemail@example.com');
```

### Deleting Data

#### Delete a Single Record

```php
Models\User::delete(1);
```

#### Delete Multiple Records

```php
$ids = [2, 3, 4];
Models\User::deleteGroup($ids);
```

## Advanced Querying

AyelaORM provides advanced querying capabilities without the need to write raw SQL. The `findWhere` and `firstWhere` methods allow you to retrieve records based on conditions.

### Simple Conditions

Provide an associative array where keys are field names and values are the values to match.

```php
$users = Models\User::findWhere(['username' => 'john_doe']);
```

Retrieve the first matching record:

```php
$user = Models\User::firstWhere(['email' => 'john@example.com']);
```

### Advanced Conditions

Provide an array of condition arrays, each containing a field, operator, and value.

```php
$users = Models\User::findWhere([
    ['age', '>', 25],
    ['status', '=', 'active']
]);
```

Supported operators: `=`, `>`, `<`, `>=`, `<=`, `!=`, `<>`, `LIKE`.

### Relationships (Joins)

AyelaORM supports relationships between models using foreign keys.

#### Defining Relationships

```php
class Author extends DatabaseObject
{
    public string $db_name;
    public string $db_email;
}

class Book extends DatabaseObject
{
    public string $db_title;
    public Author $db_author; // Relationship to Author
}
```

#### Saving Data with Relationships

```php
$author = new Models\Author();
$author->db_name = 'Jane Doe';
$author->db_email = 'jane@example.com';
$author->save();

$book = new Models\Book();
$book->db_title = 'Learning AyelaORM';
$book->db_author = $author; // Set the Author object
$book->save();
```

#### Retrieving Data with Relationships

```php
$book = Models\Book::getById(1);
echo $book->db_title; // Outputs: Learning AyelaORM
echo $book->db_author->db_name; // Outputs: Jane Doe
```

### One-to-Many and Many-to-Many Relationships

#### One-to-Many Example

In the `Author` class, add a method to retrieve related `Book` objects:

```php
class Author extends DatabaseObject
{
    // ...

    public function getBooks(): array
    {
        return Models\Book::findWhere([['author', '=', $this->db_id]]);
    }
}

// Usage
$author = Models\Author::getById(1);
$books = $author->getBooks();
```

#### Many-to-Many Example

Create a join table model:

```php
class Student extends DatabaseObject
{
    public string $db_name;

    public function enrollInCourse(Course $course)
    {
        $enrollment = new StudentCourse();
        $enrollment->db_student = $this;
        $enrollment->db_course = $course;
        $enrollment->save();
    }

    public function getCourses(): array
    {
        $enrollments = StudentCourse::findWhere([['student', '=', $this->db_id]]);
        return array_map(fn($enrollment) => $enrollment->db_course, $enrollments);
    }
}

class Course extends DatabaseObject
{
    public string $db_title;

    public function getStudents(): array
    {
        $enrollments = StudentCourse::findWhere([['course', '=', $this->db_id]]);
        return array_map(fn($enrollment) => $enrollment->db_student, $enrollments);
    }
}

class StudentCourse extends DatabaseObject
{
    public Student $db_student;
    public Course $db_course;
}
```

## Schema Management

AyelaORM automatically manages database schema changes. Whenever you define or modify properties in your models, AyelaORM checks and updates the table structure accordingly.

### Automatic Schema Updates

If you set `frozen` to `false` during the database setup, the schema will be checked and updated on each model instantiation. Tables will be created if they do not exist, and columns will be added or modified when changes are detected.

```php
Database::setup('localhost', 'database_name', 'username', 'password', false);
```

### Custom SQL Types

You can specify custom SQL types for your model properties using the `#[SQLType("...")]` attribute.

```php
class Article extends DatabaseObject
{
    #[SQLType("TEXT")]
    public string $db_content;

    #[SQLType("VARCHAR(100) UNIQUE")]
    public string $db_slug;
}
```

### Ignoring Properties

To exclude a property from the database schema, use the `#[SQLIgnore]` attribute.

```php
class User extends DatabaseObject
{
    #[SQLIgnore]
    public string $password; // Will not be stored in the database
}
```

## Data Types and Serialization

AyelaORM handles various data types, including:

- **Scalar Types**: `int`, `float`, `string`, `bool`
- **DateTime**: Automatically converted to and from database date-time format
- **DatabaseObject Subclasses**: Stored as foreign keys (relationships)
- **Arrays and Serializable Objects**: Serialized to JSON before storing and deserialized when retrieving

### Example of Serializable Object

```php
class UserProfile
{
    public string $bio;
    public array $interests;
}

class User extends DatabaseObject
{
    public string $db_username;
    public UserProfile $db_profile; // Serializable object
}

// Saving
$profile = new UserProfile();
$profile->bio = 'Developer';
$profile->interests = ['coding', 'music'];

$user = new User();
$user->db_username = 'johndoe';
$user->db_profile = $profile;
$user->save();

// Retrieving
$user = User::getById(1);
echo $user->db_profile->bio; // Outputs: Developer
```

## Error Handling

If an error occurs during database operations, it is stored in the `$last_error` property of the object.

```php
$user = new Models\User();
$user->db_username = 'john_doe';

// Intentionally cause an error (e.g., missing required field)
if (!$user->save()) {
    echo "Error: " . $user->last_error->getMessage();
}
```

---

Feel free to reach out or open an issue if you encounter any problems or have suggestions for improvements!

---

**Note**: This documentation covers the latest features and provides examples to help you get started with AyelaORM. The package is designed to simplify your database interactions and manage relationships efficiently, allowing you to focus on building your application.