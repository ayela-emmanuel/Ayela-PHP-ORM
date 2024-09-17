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

1. Clone the repository or download the source code.
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
You can also specify the SQL type for each property using the SQLType attribute.


