import json
import os

# Create output directory if it doesn't exist
output_dir = 'database/seeders/seed_data/emails'
os.makedirs(output_dir, exist_ok=True)

# Read the large JSON file
with open('database/seeders/seed_data/250507_emails.json', 'r') as f:
    data = json.load(f)

# Write each entry to its own file
for key, value in data.items():
    output_file = os.path.join(output_dir, f'{key}.json')
    with open(output_file, 'w') as f:
        json.dump(value, f, indent=2)

print(f'Successfully split {len(data)} entries into individual files in {output_dir}/') 