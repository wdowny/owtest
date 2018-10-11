Test task for DevOps

Optimized for: Amazon Linux AMI 2018.03.0 (HVM)

Script expects Security Credentials Access Key in standart path ( ~/.aws/credentials )
User who invoke script at the first stage should have rw access to repo / script dir.

Rocket launch:
  prompt$ php aws.php

HTTP service:
  prompt$ php aws.php --http

Output:
  some messages and pauses during script flow. URL and its content will be produced after well finished job.
  
Tested at least in two different AWS environment.

Objects created by the script:
- one Key Pair with custom name
- one Security Group with custom name
- one EC2 Instance (using token for idempotency)
- if not attached, one additional 1GB standard volume

Known issues:
- bad timeout control
- bad block / command completion control
- different AMI ID for different zones
- and moar...