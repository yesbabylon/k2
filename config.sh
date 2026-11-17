#!/bin/bash

# Define the .env file
ENV_FILE=".env"

# Function to prompt for a value and save it to the .env file
ask_and_save() {
    local var_name=$1
    local prompt_text=$2
    read -p "$prompt_text" value
    echo "$var_name=$value" >> "$ENV_FILE"
}

# Remove the old .env file if it exists
if [ -f "$ENV_FILE" ]; then
    rm "$ENV_FILE"
fi

echo "Creating .env file..."

touch "$ENV_FILE"

# Prompt the user for values
ask_and_save "PRIVATE_IP" "Enter the private IP address for the K2 listener: "
ask_and_save "BACKUPS_DISK" "Enter the XFS disk to use for backups (optional): "
ask_and_save "BACKUPS_PATH" "Enter the absolute path of the backup folder: "
ask_and_save "MAX_TOKEN" "Enter the maximum number of concurrent FTP transfer tokens: "
ask_and_save "TOKEN_VALIDITY" "Enter the backup token validity period in seconds: "

# Display the generated file
printf '\n.env file created successfully:\n'
cat "$ENV_FILE"
