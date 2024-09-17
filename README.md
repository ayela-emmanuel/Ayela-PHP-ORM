# AyelaORM - PHP ORM Package

**AyelaORM** is a lightweight PHP Object-Relational Mapping (ORM) package designed to simplify database interactions using PHP's PDO extension. It provides an easy-to-use interface for managing database schemas and objects, and dynamically handles table creation and updates, reducing the need for manual schema management.

## Table of Contents
1. [Installation](#installation)
2. [Usage](#usage)
   - [Database Setup](#database-setup)
   - [Creating Models](#creating-models)
   - [Saving and Retrieving Data](#saving-and-retrieving-data)
3. [Schema Management](#schema-management)
4. [Advanced Features](#advanced-features)
   - [Custom SQL Types](#custom-sql-types)
   - [Schema Updates](#schema-updates)
5. [License](#license)

## Installation

1. Clone the repository or download the source code or use composer:
```
   composer require ayela-emmanuel/ayela-orm
```
2. Ensure that the `PDO` extension is enabled for your PHP installation (MySQL is the default).
3. Add the necessary namespace `AyelaORM` to your project.

## Usage

### Database Setup

Before using AyelaORM, you must set up the database connection:

```php
use AyelaORM\Database;

Database::setup('localhost', 'database_name', 'username', 'password', false);
```

- host: Database server host (e.g., localhost).
- db: Database name.
- username: Database username.
- password: Database password.
- frozen: If true, the database schema will not be checked or updated automatically.


Creating Models
To create a model that interacts with the database, extend the DatabaseObject class. Any property prefixed with db_ will be considered as a database column:

```php
namespace Models;
use AyelaORM\DatabaseObject;
use AyelaORM\SQLType;

class User extends DatabaseObject {
    #[SQLType("VARCHAR(255)")]
    public string $db_username;
    
    #[SQLType("VARCHAR(255)")]
    public string $db_email;
    
    #[SQLType("INT")]
    public int $db_age;
}
```

Note: Properties with the db_ prefix are automatically handled as database columns.  
  
- You can also specify the SQL type for each property using the SQLType attribute.


## Saving and Retrieving Data
### Saving Data
To save a new object to the database:

```php
$user = new Models\User();
$user->db_username = 'john_doe';
$user->db_email = 'john@example.com';
$user->db_age = 30;
$user->save();
```
### Retrieving Data
You can retrieve records using several built-in methods:
```php
$users = Models\User::list(1, 10); // Retrieve the first 10 users.
```
Retrieve a record by ID:
```php
$user = Models\User::getById(1);
```
Retrieve the first record:
```php
$user = Models\User::first();
```
*More Coming soon*


## Schema Management
AyelaORM automatically manages database schema changes. Whenever you define or modify properties in your models, AyelaORM checks and updates the table structure accordingly.  

If you set frozen to false during the database setup, the schema will be checked and updated on each model instantiation. Tables will be created if they do not exist, and columns will be added or modified when changes are detected.  

To disable automatic schema updates (i.e., freeze the schema), set the frozen parameter to true during setup:

```php
Database::setup('localhost', 'database_name', 'username', 'password', true);
```
## Advanced Features
### Custom SQL Types
You can specify custom SQL types for your model properties using the SQLType attribute:

```php
#[SQLType("TEXT")]
public string $db_bio;
//or
#[SQLType("VARCHAR(255)")]
public string $db_desc;
```

### Schema Updates
When a model's schema is updated, AyelaORM compares the new schema with the existing one in the database. It automatically generates the necessary ALTER TABLE statements and applies them if there are differences, ensuring your database schema stays synchronized with your models.

To ensure a schema update or refresh:
```php
Database::setup('localhost', 'database_name', 'username', 'password', false);// Set Frozen to false
```

Feel free to reach out/open issue if you encounter any issues or have suggestions for improvements!  
