#!/bin/bash

# Infinite loop to run the PHP script every 5 seconds in the background
while true
do
    php second.php &  # Run PHP script in the background
    sleep 3
done