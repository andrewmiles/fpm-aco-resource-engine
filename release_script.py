# release_script.py
# A script to automate the release process for a WordPress plugin.

import os
import re
import subprocess

# --- CONFIGURATION ---
# The name of the main plugin file to modify.
PLUGIN_FILE_NAME = "fpm-aco-resource-engine.php"
# --- END CONFIGURATION ---

def find_and_bump_version(file_path):
    """
    Finds the version line in the plugin file and increments the patch number.
    Example: 1.15.4 becomes 1.15.5
    """
    print(f"Reading file: {file_path}...")
    try:
        with open(file_path, 'r') as f:
            lines = f.readlines()

        new_lines = []
        version_found = False
        old_version = ""
        new_version = ""

        # Use regex to find the version line reliably
        version_pattern = re.compile(r"(\s\*\sVersion:\s+)(\d+\.\d+\.)(\d+)")

        for line in lines:
            match = version_pattern.search(line)
            if match and not version_found:
                version_found = True
                prefix = match.group(1) # e.g., " * Version:     "
                major_minor = match.group(2) # e.g., "1.15."
                patch = int(match.group(3)) # e.g., 4

                old_version = f"{major_minor}{patch}"
                new_patch = patch + 1
                new_version = f"{major_minor}{new_patch}"

                new_line = f"{prefix}{new_version}\n"
                new_lines.append(new_line)
                print(f"  Found version {old_version}. Bumping to {new_version}.")
            else:
                new_lines.append(line)

        if not version_found:
            print("  ERROR: Could not find the version line in the file. Aborting.")
            return None, None

        # Write the changes back to the file
        with open(file_path, 'w') as f:
            f.writelines(new_lines)
        print(f"  File saved successfully with new version.")
        return old_version, new_version

    except FileNotFoundError:
        print(f"  ERROR: The file '{file_path}' was not found. Make sure you're in the right directory.")
        return None, None

def get_commit_message(version):
    """
    Asks the user for a commit message, offering a default.
    """
    default_message = f"chore: Release version {version}"
    
    while True:
        response = input(f"\nUse default commit message: '{default_message}'? (Y/n): ").lower().strip()
        if response in ['y', 'yes', '']:
            return default_message
        elif response in ['n', 'no']:
            custom_message = input("Enter your custom commit message: ").strip()
            if custom_message:
                return custom_message
            else:
                print("  ERROR: Commit message cannot be empty.")
        else:
            print("  Invalid input. Please enter 'y' or 'n'.")


def run_git_commands(commit_message):
    """
    Runs the git add, commit, and push commands.
    """
    print("\nRunning Git commands...")
    try:
        print("  1. Staging changes (git add .)")
        subprocess.run(["git", "add", "."], check=True)

        print(f"  2. Committing changes (git commit -m \"{commit_message}\")")
        subprocess.run(["git", "commit", "-m", commit_message], check=True)

        print("  3. Pushing to origin (git push)")
        subprocess.run(["git", "push"], check=True)
        
        print("\nProcess complete! Changes have been pushed to GitHub.")
        return True
    except subprocess.CalledProcessError:
        print("\n  ERROR: A Git command failed. Please check the output above and resolve any issues.")
        return False
    except FileNotFoundError:
        print("\n  ERROR: 'git' command not found. Is Git installed and in your PATH?")
        return False

# --- Main script execution ---
if __name__ == "__main__":
    if not os.path.exists(PLUGIN_FILE_NAME):
        print(f"ERROR: Cannot find the plugin file '{PLUGIN_FILE_NAME}'.")
        print("Please make sure you run this script from your plugin's root directory.")
    else:
        old_ver, new_ver = find_and_bump_version(PLUGIN_FILE_NAME)

        if new_ver:
            commit_msg = get_commit_message(new_ver)
            run_git_commands(commit_msg)