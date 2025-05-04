# SQL AI Optimizer

This tool leverages AI to analyze and optimize SQL queries for better performance. It can pinpoint specific queries that are causing performance issues and suggest optimizations.

You can also specify special instructions for the AI to follow (e.g. "focus on queries consuming memory").

## Description

SQL AI Optimizer is a PHP application that connects to databases, analyzes SQL queries, and uses Anthropic's AI models to suggest optimizations, identify performance bottlenecks, and provide insights to improve database efficiency.

## Screenshots
![Index](./assets/index.png)

--------------------------------

![Groups](./assets/groups.png)

--------------------------------

![Analysis summary](./assets/analysis-summary.png)

--------------------------------

![Group](./assets/group.png)  

--------------------------------

![Result](./assets/result.png)  

## Features

- Connect to MySQL/MariaDB databases
- Analyze query performance using Performance Schema
- AI-powered query optimization suggestions
- Performance metrics visualization
- Query history tracking
- Export results as offline HTML

## Requirements

- PHP 8.2+
- Composer
- MySQL/MariaDB database with Performance Schema enabled
- Anthropic API key

## Security

This tool runs queries suggested by the LLM on your database. Queries and their results are sent to the LLM. It is important to use a read-only user with limited privileges.

The LLM is instructed to only get statistical data from the database, but it cannot be guaranteed that it will follow this instruction. If your queries contain sensitive data, it could be sent to the LLM.

**This tool is LLM powered SQL injection and should be run only locally and disabled after completing the analysis.**

## Installation

1. Clone the repository:
   ```
   git clone https://github.com/soukicz/sql-ai-optimizer.git
   cd sql-ai-optimizer
   ```

2. Install dependencies:
   ```
   composer install
   ```

3. Create a `.env` file in the project root with your [Anthropic API key](https://console.anthropic.com/settings/keys) and database connection string:
   ```
   ANTHROPIC_API_KEY=your_api_key_here
   DATABASE_URL=mysql://user:password@host:port/
   ```

## Usage

1. Start the application:
   ```
   php -S 127.0.0.1:8000
   ```

2. Connect to your database via SSH tunnel if needed:
   ```
   ssh -L 33306:your-db-host:3306 user@your-ssh-server
   ```

3. Access the application at http://127.0.0.1:8000

## Project Structure

This tool is as simple as possible with few dependencies by design. It is accessing production databases and it should be easy to evaluate what it is doing.

- `src/` - Application source code
- `templates/` - Twig templates for the web interface
- `data/` - Application data including state database
- `var/` - Cache and logs

## MySQL Configuration
### User
Create a user with read-only privileges. It should get access to at least the performance_schema. You will get best results by giving full SELECT privileges to all tables.

The database must be either production or a copy of production. This tool is checking query history and needs real traffic to provide useful results.

```sql
CREATE USER 'user'@'your_ip' IDENTIFIED BY 'password';
GRANT SELECT, SHOW VIEW ON performance_schema.* TO 'user'@'your_ip';
GRANT SELECT, SHOW VIEW ON *.* TO 'user'@'your_ip';
```

### Performance Schema

 - Enable [performance schema](https://dev.mysql.com/doc/refman/8.4/en/performance-schema-quick-start.html)
 - Configure [performance_schema_digests_size](https://dev.mysql.com/doc/refman/8.4/en/performance-schema-system-variables.html#sysvar_performance_schema_digests_size) to store more query digests
 - Configure [performance_schema_max_digest_length](https://dev.mysql.com/doc/refman/8.4/en/performance-schema-system-variables.html#sysvar_performance_schema_max_digest_length) to see full queries and to avoid merging of results for queries with the same beginning (default is 1024 characters)
 - Enable the larger query table to get exact queries for analysis by running `UPDATE performance_schema.setup_consumers SET enabled = 'YES' WHERE name = 'events_statements_history_long';`


Performance statistics should be collected for reasonable time before running the optimizer - at least a few hours, ideally few days. You can reset statistics after optimizing queries by running:

```sql
CALL sys.ps_truncate_all_tables(FALSE);
```
