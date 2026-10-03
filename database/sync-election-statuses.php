<?php
function syncElectionStatuses(mysqli $conn): void
{
	$conn->query("UPDATE elections SET status = 'ongoing' WHERE status = 'scheduled' AND start_date <= NOW() AND end_date > NOW()");
	$conn->query("UPDATE elections SET status = 'ended' WHERE status IN ('scheduled', 'ongoing') AND end_date <= NOW()");
}
