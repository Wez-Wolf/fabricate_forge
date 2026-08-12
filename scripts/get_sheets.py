#!/usr/bin/env python3

import sys
import os
import subprocess

# Try to use the same approach as the existing BOM importer
try:
    # Try to use the xlsx library that's already in your project
    result = subprocess.run([
        'node', '-e', '''
        const XLSX = require("xlsx");
        const workbook = XLSX.readFile("example.xlsm");
        console.log("Sheet names in example.xlsm:");
        workbook.SheetNames.forEach((name, i) => {
            console.log((i+1) + ". " + name);
        });
        console.log("\\nTotal sheets: " + workbook.SheetNames.length);
        '''
    ], capture_output=True, text=True, cwd='/var/www/html/personal/fabricate')
    
    if result.returncode == 0:
        print(result.stdout)
    else:
        print("Node.js approach failed:", result.stderr)
        
except Exception as e:
    print(f"Error: {e}")
    
    # Fallback: try to read as binary and look for sheet names
    try:
        with open('example.xlsm', 'rb') as f:
            content = f.read()
            print("File size:", len(content), "bytes")
            print("File starts with:", content[:20].hex())
            
            # Look for common Excel signatures
            if b'PK' in content[:10]:
                print("This is a ZIP-based Excel file (.xlsx/.xlsm)")
            else:
                print("This might be a legacy Excel file (.xls)")
                
    except Exception as e2:
        print(f"Binary read failed: {e2}")