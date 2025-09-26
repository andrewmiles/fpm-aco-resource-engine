#!/bin/bash
# This script automates running the Python release script in its virtual environment.

# Change to the script's directory, so it can be run from anywhere
cd "$(dirname "$0")"

echo "--- Starting Release Process ---"

# Activate the virtual environment
source .venv/bin/activate

# Run the Python script
python3 release_script.py

# Deactivate the virtual environment
deactivate

echo "" # Add a blank line for readability
echo "--- Process Finished ---"
read -p "Press Enter to close this window."