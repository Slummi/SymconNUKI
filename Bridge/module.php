<?php

/*
 * @module      NUKI Bridge
 *
 * @file        module.php
 *
 * prefix       NUKI
 *
 * @author      Ulrich Bittner
 * @copyright   (c) 2019
 * @license     CC BY-NC-SA 4.0
 *
 * @version     1.04
 * @build       1007
 * @date        2019-08-07, 18:00
 *
 * @see         https://github.com/ubittner/SymconNUKI
 *
 * @guids		Library
 * 				{752C865A-5290-4DBE-AC30-01C7B1C3312F}
 *
 *				Server Socket (Virtual I/O NUKI Callback)
 *				{018EF6B5-AB94-40C6-AA53-46943E824ACF} (CR:	IO_RX)
 *				{79827379-F36E-4ADA-8A95-5F8D1DC92FA9} (I: 	IO_TX)
 *
 *				NUKI Bridge (Spliter)
 *				{B41AE29B-39C1-4144-878F-94C0F7EEC725} (Module GUID)
 *
 * 				{79827379-F36E-4ADA-8A95-5F8D1DC92FA9} (PR:	IO_TX)
 *				{3DED8598-AA95-4EC4-BB5D-5226ECD8405C} (CR: Device_RX)
 *              {018EF6B5-AB94-40C6-AA53-46943E824ACF} (I:	IO_RX)
 *				{73188E44-8BBA-4EBF-8BAD-40201B8866B9} (I:	Device_TX)
 *
 */

// Declare
declare(strict_types=1);

// Include
include_once __DIR__ . '/helper/autoload.php';

class NUKIBridge extends IPSModule
{
    // Helper
    use bridgeAPI;
    use control;

    public function Create()
    {
        parent::Create();

        // Register properties
        $this->RegisterPropertyString('BridgeIP', '');
        $this->RegisterPropertyInteger('BridgePort', 8080);
        $this->RegisterPropertyInteger('Timeout', 5000);
        $this->RegisterPropertyString('BridgeID', '');
        $this->RegisterPropertyString('BridgeAPIToken', '');
        $this->RegisterPropertyBoolean('UseCallback', false);
        $this->RegisterPropertyString('SocketIP', '');
        $this->RegisterPropertyInteger('SocketPort', 8081);
        $this->RegisterPropertyInteger('CallbackID', 0);
    }

    public function ApplyChanges()
    {
        // Wait until IP-Symcon is started
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        parent::ApplyChanges();

        // Check kernel runlevel
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

        // Callback
        if ($this->ReadPropertyBoolean('UseCallback')) {
            $this->ConnectParent(SERVER_SOCKET_GUID);
            $bridgeID = $this->ReadPropertyString('BridgeID');
            if (!empty($bridgeID)) {
                $filter = '.*User-Agent: NukiBridge_' . $bridgeID . '.*';
                $this->SetReceiveDataFilter($filter);
            }
        } else {
            $parent = $this->GetParent();
            if ($parent != 0 && IPS_ObjectExists($parent)) {
                IPS_DisconnectInstance($this->InstanceID);
            }
            $filter = '.*User-Agent: NukiBridge_99999999.*';
            $this->SetReceiveDataFilter($filter);
        }

        // Validate configuration
        $this->ValidateBridgeConfiguration();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->SendDebug('MessageSink', 'SenderID: ' . $SenderID . ', Message: ' . $Message, 0);
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;
        }
    }

    /**
     * Applies changes when the kernel is ready.
     */
    private function KernelReady()
    {
        $this->ApplyChanges();
    }

    /**
     * Receives data from the server socket.
     *
     * @param $JSONString
     *
     * @return bool|void
     */
    public function ReceiveData($JSONString)
    {
        $this->SendDebug('ReceiveData', 'Incomming data for this NUKI Bridge', 0);
        $data = json_decode($JSONString);
        $data = utf8_decode($data->Buffer);
        $this->SendDebug('ReceiveData', $data, 0);
        preg_match_all('/\\{(.*?)\\}/', $data, $match);
        $smartLockData = json_encode(json_decode(implode($match[0]), true));
        $this->SendDebug('ReceiveData', $smartLockData, 0);
        $this->SetStateOfSmartLock($smartLockData, true);
    }

    /**
     * Gets the parent id.
     *
     * @return int
     */
    protected function GetParent(): int
    {
        $connectionID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        return $connectionID;
    }

    /**
     * Validates the configuration form.
     */
    private function ValidateBridgeConfiguration()
    {
        $this->SetStatus(102);
        // Check callback
        if ($this->ReadPropertyBoolean('UseCallback') == true) {
            if ($this->ReadPropertyString('SocketIP') == '' || $this->ReadPropertyInteger('SocketPort') == '') {
                $this->SetStatus(104);
            }
        }
        // Check bridge data
        if ($this->ReadPropertyString('BridgeIP') == '' || $this->ReadPropertyInteger('BridgePort') == '' || $this->ReadPropertyString('BridgeAPIToken') == '') {
            $this->SetStatus(104);
        } else {
            $reachable = false;
            $timeout = 1000;
            if ($timeout && Sys_Ping($this->ReadPropertyString('BridgeIP'), $timeout) == true) {
                $data = $this->GetBridgeInfo();
                if ($data != false) {
                    $reachable = true;
                }
            }
            if ($reachable == false) {
                $this->SetStatus(201);
            }
        }
    }
}