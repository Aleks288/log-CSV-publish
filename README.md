# Nginx Log Parser & Git Export Script

This script parses Nginx access logs, applies filtering and sorting rules, exports the result to a CSV file, and optionally commits and pushes the file to a Git repository.

---

## Features

- Parse Nginx access logs using a predefined regex
- Filter log entries using comparison operators
- Sort results by one or multiple fields
- Export parsed data to CSV
- Commit and push results to a Git repository
- Supports dry-run mode
- Can be run locally or inside Docker

---

## Requirements

### PHP
- PHP **7.4 or higher**

```bash
apt-get install -y php7.4-cli php7.4-fpm
```
- Git

```bash
apt-get install git
```

- Docker (can be used to run the script inside a container)

---

## Environment Configuration

The script requires a .env file to run.

Example .env file
```
LOG_FILE_PATH=/var/www/html/nginx.log
OUTPUT_FILE_NAME=output
FILE_SAVE_PATH=/tmp/csvFolder
GIT_SSH_URL=git@github.com:User/repo-name.git
GIT_USER=test
GIT_EMAIL=service@test.com
GIT_BRANCH=dev
SSH_KEY_PATH=/var/www/html/id_rsa
```

⚠️ If GIT_SSH_URL is not provided, the script will not push changes to the repository.

---

# Usage

## Local execution
```bash
php index.php \
--sort datetime=DESC bytes_sent=ASC \
--filter 'datetime>26/Apr/2021:21:20:22 +0000' 'request_id%45' \
--message 'Test message' \
--dry-run
```

## Docker execution
```bash
docker build -t my-image .

docker run \                                    
  --env-file .env \
  -v ~/.ssh/id_rsa_git:/var/www/html/id_ssh:ro \
  my-image \
  --sort datetime=DESC bytes_sent=ASC \
  --filter 'datetime>26/Apr/2021:21:20:22 +0000' 'request_id%45' \
  --message 'Test message' \
  --dry-run
```

---

# Command Line Arguments
- ``--sort``

Sort results by one or more fields. Format: ``field=asc|desc``

Example:
``
--sort datetime=DESC bytes_sent=ASC
``

- ``--filter``

Filter log records using comparison operators. Supported operators: ``>   <   >=   <=   =   !=   %   !%``

- % — contains
- !% — does not contain

Example:
``
--filter 'response_code>=400' 'request_id%45'
``

- ``--message``

Commit message for Git. To the main message also added date and time of script execution

Example:
``
--message 'Daily log export'
``


- ``--dry-run``

Runs the script without commiting and pushing changes to Git

---

# Log Parsing

### Logs are parsed using the following regex:

```bash
/^(\S+)\s(.*?)\s(.*?)\s\[(.*?)\]\s\"(([A-Z]+)\s(\S+)\s(.*?))\"\s(\d{3})\s(\d+)\s\"(.*?)\"\s\"(.*?)\"\s(\d+)\s(\d+\.\d+)\s\[(.*?)\]\s\[(.*?)\]\s(\S+)\s(\d+)\s(\d+\.\d+)\s(\d{3})\s(.*)$/
```

The regex extracts 17 fields. The following fields can be used for filtering and sorting:

- ip
- datetime
- http_method
- api_endpoint
- http_version
- response_code
- bytes_sent
- http_referer
- user_agent
- request_length
- request_time
- upstream_addr
- upstream_ip
- upstream_response_length
- upstream_response_time
- upstream_status
- request_id

---

# Output Behavior

- If no records remain after filtering, the script stops execution
- Output CSV is saved to FILE_SAVE_PATH
- Output CSV has suffix in name as datetime (e.g. output_20260213_151026.csv)
- If connection with GIT is not established, script execution is stopped

---

# Git Integration

The script supports stateful Git operations.


If the directory is not initialized:

- Git repository will be initialized

On subsequent runs:

- Resets GIT configs (user, email, origin url, ssh key)

- Checkouts branch

- Pulls latest changes

- Generate the CSV file

- Creates a new commit (if dry run is not active)

- Pushes changes to the configured branch (if dry run is not active)

### Docker note
When running inside Docker, Git state is not preserved unless a volume is mounted.

### GIT note
If GIT user, email and branch are not specified in the .env file, the standard values will be used:

- user 'test'
- email 'service@test.com'
- branch 'dev' 
️
---

# SSH Access

To push changes:

- An SSH key must be provided
- Repository connectivity is verified during execution
- If authentication fails, changes will not be pushed


---

# Example Workflow

- Init Git repo with configs. Check connectivity
- Parse Nginx logs
- Apply filters and sorting
- Export results to CSV
- Commit changes
- Push to Git repository

---

# Notes

- Script stops execution if connection to GIT is failed
- Script stops execution if the parsed log file is empty
- Docker execution is stateless unless volumes are mounted