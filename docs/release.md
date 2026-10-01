# Preparing a public repository

Run all quality gates, then `python3 scripts/export_public.py --check` and `python3 scripts/export_public.py`. The allowlisted source archive excludes Git history, private configuration, identity material, runtime databases, vendor, node_modules and IDE files. The automated scan complements review; it is not a proof that arbitrary secrets cannot exist.

Extract the archive into a fresh directory and initialize a new Git repository there. Review the complete export, dependency notices and attribution before publication. Enable private vulnerability reporting in the new repository. Do not push the existing private project's Git history.

This workflow prepares a distribution locally; it does not create or publish a GitHub repository.
