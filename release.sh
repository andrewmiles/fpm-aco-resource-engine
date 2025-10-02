#!/bin/bash
# This script automates the release and deployment of the WordPress plugin.

# Change to the script's directory, so it can be run from anywhere
cd "$(dirname "$0")"

echo "--- Starting Release Process ---"

# Activate the virtual environment
source .venv/bin/activate

# Run the Python script
python3 release_script.py

# Capture the exit code from the Python script
RELEASE_EXIT_CODE=$?

# Deactivate the virtual environment
deactivate

# Check if the Python script succeeded
if [ $RELEASE_EXIT_CODE -ne 0 ]; then
    echo ""
    echo "ERROR: Release script failed with exit code $RELEASE_EXIT_CODE"
    echo "Deployment cancelled."
    exit $RELEASE_EXIT_CODE
fi

echo ""
echo "--- Release Successful, Starting Deployment ---"
echo "Using 'tar' to deploy as rsync is not available on the remote server..."

# This command archives the local files, sends them over SSH, and unpacks them on the server.
# Exclusions for .git, .sh files, and the .venv directory are included.
tar --exclude='.git' --exclude='*.sh' --exclude='.venv' -czf - . | ssh acomain@5.134.12.230 "mkdir -p /home/acomain/public_html/wp-content/plugins/fpm-aco-resource-engine && tar -xzf - -C /home/acomain/public_html/wp-content/plugins/fpm-aco-resource-engine"

# Capture the exit code from the deployment command
DEPLOY_EXIT_CODE=$?

echo ""
if [ $DEPLOY_EXIT_CODE -eq 0 ]; then
    echo "--- Deployment Successful ---"
else
    echo "ERROR: Deployment failed with exit code $DEPLOY_EXIT_CODE"
    exit $DEPLOY_EXIT_CODE
fi

echo "--- Process Finished ---"
