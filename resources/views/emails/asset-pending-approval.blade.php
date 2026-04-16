{{-- MODE: system | Asset Pending Approval --}}
<x-email.layout title="Asset pending approval" preheader="A new asset requires your approval">

    <x-email.eyebrow>Approval Required</x-email.eyebrow>
    <x-email.heading>Asset pending approval</x-email.heading>

    <x-email.text>A new asset has been uploaded and requires your approval.</x-email.text>

    <x-email.details :items="[
        'Asset' => $assetName,
        'Category' => $categoryName,
        'Uploaded by' => $uploaderName,
        'Uploaded' => $uploadTimestamp,
    ]" />

    <x-email.button :url="$approvalUrl">Review pending assets</x-email.button>

    <x-email.divider />

    <x-email.text :muted="true">You receive this because you have permission to approve assets.</x-email.text>

</x-email.layout>
