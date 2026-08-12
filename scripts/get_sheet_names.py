#!/usr/bin/env python3

import sys
import os

# Add the current directory to Python path
sys.path.insert(0, '/var/www/html/personal/fabricate')

try:
    import openpyxl
    
    # Open the Excel file
    workbook = openpyxl.load_workbook('example.xlsm', data_only=True)
    
    # Get sheet names
    sheet_names = workbook.sheetnames
    
    print("Sheet names in example.xlsm:")
    for i, name in enumerate(sheet_names, 1):
        print(f"{i}. {name}")
        
    print(f"\nTotal sheets: {len(sheet_names)}")
    
except ImportError:
    print("openpyxl not available, trying xlrd...")
    try:
        import xlrd
        
        workbook = xlrd.open_workbook('example.xlsm')
        sheet_names = workbook.sheet_names()
        
        print("Sheet names in example.xlsm:")
        for i, name in enumerate(sheet_names, 1):
            print(f"{i}. {name}")
            
        print(f"\nTotal sheets: {len(sheet_names)}")
        
    except ImportError:
        print("Neither openpyxl nor xlrd available")
    except Exception as e:
        print(f"Error reading with xlrd: {e}")
        
except Exception as e:
    print(f"Error reading with openpyxl: {e}")